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
}
