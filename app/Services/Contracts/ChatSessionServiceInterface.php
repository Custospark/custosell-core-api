<?php

namespace App\Services\Contracts;

use App\Models\ChatSession;
use Illuminate\Database\Eloquent\Collection;

interface ChatSessionServiceInterface
{
    public function list(int $userId, ?int $businessId): Collection;

    public function open(int $id, int $userId): ChatSession;

    public function rename(int $id, int $userId, string $title): ChatSession;

    public function remove(int $id, int $userId): bool;

    /** Create a session for the first turn; title comes from the first message. */
    public function start(int $userId, ?int $businessId, string $firstMessage): ChatSession;

    /** Append a completed turn; refreshes recency and auto-titles default sessions. */
    public function appendTurn(ChatSession $session, string $userMessage, string $assistantMessage): void;

    /**
     * Merge stored session turns under the incoming messages so the model
     * sees the full conversation even when the client only sends the latest
     * turn (reload, new tab). Stored turns already replayed by the client
     * are deduplicated; output is capped to the most recent window.
     *
     * @param list<array{role?: string, content?: string}> $incoming
     * @return list<array{role: string, content: string}>
     */
    public function contextWithHistory(ChatSession $session, array $incoming): array;
}
