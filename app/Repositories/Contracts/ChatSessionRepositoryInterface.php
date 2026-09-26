<?php

namespace App\Repositories\Contracts;

use App\Models\ChatSession;
use Illuminate\Database\Eloquent\Collection;

interface ChatSessionRepositoryInterface
{
    public function listForUser(int $userId, ?int $businessId): Collection;

    public function findForUser(int $id, int $userId): ?ChatSession;

    public function create(array $data): ChatSession;

    public function update(ChatSession $session, array $data): ChatSession;

    public function delete(ChatSession $session): bool;
}
