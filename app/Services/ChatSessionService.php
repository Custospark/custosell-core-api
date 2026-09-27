<?php

namespace App\Services;

use App\Models\ChatMessage;
use App\Models\ChatSession;
use App\Repositories\Contracts\ChatSessionRepositoryInterface;
use App\Services\Contracts\ChatSessionServiceInterface;
use Illuminate\Database\Eloquent\Collection;
use RuntimeException;

class ChatSessionService implements ChatSessionServiceInterface
{
    public function __construct(
        protected ChatSessionRepositoryInterface $sessions,
    ) {}

    public function list(int $userId, ?int $businessId): Collection
    {
        return $this->sessions->listForUser($userId, $businessId);
    }

    public function open(int $id, int $userId): ChatSession
    {
        $session = $this->sessions->findForUser($id, $userId);
        if (! $session) {
            throw new RuntimeException('Chat session not found');
        }

        return $session;
    }

    public function rename(int $id, int $userId, string $title): ChatSession
    {
        $session = $this->open($id, $userId);
        $title = trim($title);

        return $this->sessions->update($session, [
            'title' => $title !== '' ? mb_substr($title, 0, 120) : $session->title,
        ]);
    }

    public function remove(int $id, int $userId): bool
    {
        return $this->sessions->delete($this->open($id, $userId));
    }

    public function start(int $userId, ?int $businessId, string $firstMessage): ChatSession
    {
        return $this->sessions->create([
            'user_id' => $userId,
            'business_id' => $businessId,
            'title' => ChatSession::titleFrom($firstMessage),
        ]);
    }

    public function contextWithHistory(ChatSession $session, array $incoming): array
    {
        $maxMessages = max(1, (int) config('assistant.max_messages', 20));
        $maxChars = max(1, (int) config('assistant.max_chars_per_message', 2000));

        $seen = [];
        foreach ($incoming as $message) {
            $seen[($message['role'] ?? '')."\0".((string) ($message['content'] ?? ''))] = true;
        }

        $merged = [];
        $stored = ChatMessage::query()
            ->where('chat_session_id', $session->id)
            ->orderBy('id')
            ->get(['role', 'content']);
        foreach ($stored as $message) {
            $key = ((string) $message->role)."\0".((string) $message->content);
            if (! isset($seen[$key])) {
                $merged[] = ['role' => (string) $message->role, 'content' => (string) $message->content];
                $seen[$key] = true;
            }
        }
        foreach ($incoming as $message) {
            $merged[] = [
                'role' => ($message['role'] ?? null) === 'assistant' ? 'assistant' : 'user',
                'content' => mb_substr((string) ($message['content'] ?? ''), 0, $maxChars),
            ];
        }

        return array_values(array_slice($merged, -$maxMessages));
    }

    public function appendTurn(ChatSession $session, string $userMessage, string $assistantMessage): void
    {
        ChatMessage::create([
            'chat_session_id' => $session->id,
            'role' => ChatMessage::ROLE_USER,
            'content' => $userMessage,
        ]);
        ChatMessage::create([
            'chat_session_id' => $session->id,
            'role' => ChatMessage::ROLE_ASSISTANT,
            'content' => $assistantMessage,
        ]);

        if ($session->title === 'New chat') {
            $session->update(['title' => ChatSession::titleFrom($userMessage)]);
        }
        $session->touch();
    }
}
