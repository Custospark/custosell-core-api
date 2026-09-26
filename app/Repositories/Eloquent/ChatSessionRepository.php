<?php

namespace App\Repositories\Eloquent;

use App\Models\ChatSession;
use App\Repositories\Contracts\ChatSessionRepositoryInterface;
use Illuminate\Database\Eloquent\Collection;

class ChatSessionRepository implements ChatSessionRepositoryInterface
{
    public function listForUser(int $userId, ?int $businessId): Collection
    {
        return ChatSession::where('user_id', $userId)
            ->when($businessId !== null, fn ($query) => $query->where('business_id', $businessId))
            ->withCount('messages')
            ->latest('updated_at')
            ->get();
    }

    public function findForUser(int $id, int $userId): ?ChatSession
    {
        return ChatSession::where('id', $id)
            ->where('user_id', $userId)
            ->with('messages')
            ->first();
    }

    public function create(array $data): ChatSession
    {
        return ChatSession::create($data);
    }

    public function update(ChatSession $session, array $data): ChatSession
    {
        $session->update($data);

        return $session->fresh();
    }

    public function delete(ChatSession $session): bool
    {
        return $session->delete();
    }
}
