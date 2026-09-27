<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Assistant\AssistantChatService;
use App\Services\Assistant\AssistantException;
use App\Services\Contracts\ChatSessionServiceInterface;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AssistantController extends Controller
{
    public function __construct(
        private AssistantChatService $chat,
        private ChatSessionServiceInterface $sessions,
    ) {}

    public function chat(Request $request): JsonResponse
    {
        $messages = $this->validatedMessages($request);

        $user = $request->user();
        $businessId = (int) $user->business_id;
        $businessName = (string) ($user->business->name ?? 'your business');

        $sessionId = $request->integer('session_id') ?: null;
        $session = null;
        if ($sessionId !== null) {
            $session = $this->sessions->open($sessionId, (int) $user->id);
            // Server-side continuity: stored turns join the model context so
            // follow-ups ("it", "that one", "and for June?") resolve even
            // when the client only sends the latest turn.
            $messages = $this->sessions->contextWithHistory($session, $messages);
        }

        try {
            $reply = $this->chat->reply($businessId, $businessName, $messages, $user);
        } catch (AssistantException $e) {
            return response()->json(['message' => $e->getMessage()], 502);
        }

        // Persist the completed turn; sessions belong to the user, guests stay ephemeral.
        $lastUser = '';
        foreach (array_reverse($messages) as $message) {
            if (($message['role'] ?? null) === 'user') {
                $lastUser = (string) ($message['content'] ?? '');
                break;
            }
        }
        if ($session === null) {
            $session = $this->sessions->start((int) $user->id, $user->business_id ? (int) $user->business_id : null, $lastUser);
        }
        $this->sessions->appendTurn($session, $lastUser, $reply);

        return response()->json(['data' => [
            'reply' => $reply,
            'session' => ['id' => $session->id, 'title' => $session->title],
        ]]);
    }

    /** Guest how-to answers for landing/auth pages - no business data. */
    public function guide(Request $request): JsonResponse
    {
        return $this->answer(null, 'Custosell ERP', $this->validatedMessages($request));
    }

    public function indexSessions(Request $request): JsonResponse
    {
        $user = $request->user();
        $sessions = $this->sessions->list(
            (int) $user->id,
            $user->business_id ? (int) $user->business_id : null
        );

        return response()->json(['data' => $sessions->map(fn ($session) => [
            'id' => $session->id,
            'title' => $session->title,
            'messages_count' => $session->messages_count ?? 0,
            'updated_at' => $session->updated_at?->toISOString(),
        ])->all()]);
    }

    public function showSession(Request $request, int $id): JsonResponse
    {
        try {
            $session = $this->sessions->open($id, (int) $request->user()->id);
        } catch (\RuntimeException) {
            abort(404, 'Chat session not found');
        }

        return response()->json(['data' => [
            'id' => $session->id,
            'title' => $session->title,
            'updated_at' => $session->updated_at?->toISOString(),
            'messages' => $session->messages->map(fn ($message) => [
                'role' => $message->role,
                'content' => $message->content,
                'created_at' => $message->created_at?->toISOString(),
            ])->all(),
        ]]);
    }

    public function renameSession(Request $request, int $id): JsonResponse
    {
        $data = $request->validate(['title' => ['required', 'string', 'max:120']]);
        try {
            $session = $this->sessions->rename($id, (int) $request->user()->id, $data['title']);
        } catch (\RuntimeException) {
            abort(404, 'Chat session not found');
        }

        return response()->json(['data' => ['id' => $session->id, 'title' => $session->title]]);
    }

    public function destroySession(Request $request, int $id): JsonResponse
    {
        try {
            $this->sessions->remove($id, (int) $request->user()->id);
        } catch (\RuntimeException) {
            abort(404, 'Chat session not found');
        }

        return response()->json(null, 204);
    }

    /** @return list<array{role: string, content: string}> */
    private function validatedMessages(Request $request): array
    {
        $maxMessages = (int) config('assistant.max_messages', 20);
        $maxChars = (int) config('assistant.max_chars_per_message', 2000);

        $validated = $request->validate([
            'messages' => ['required', 'array', 'min:1', 'max:'.$maxMessages],
            'messages.*.role' => ['required', 'string', 'in:user,assistant'],
            'messages.*.content' => ['required', 'string', 'min:1', 'max:'.$maxChars],
        ]);

        return $validated['messages'];
    }

    /** @param list<array{role: string, content: string}> $messages */
    private function answer(?int $businessId, string $businessName, array $messages, ?\App\Models\User $user = null): JsonResponse
    {
        try {
            $reply = $this->chat->reply($businessId, $businessName, $messages, $user);
        } catch (AssistantException $e) {
            return response()->json(['message' => $e->getMessage()], 502);
        }

        return response()->json(['data' => ['reply' => $reply]]);
    }
}
