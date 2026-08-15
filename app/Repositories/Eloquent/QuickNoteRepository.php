<?php

namespace App\Repositories\Eloquent;

use App\Models\QuickNote;
use App\Repositories\Contracts\QuickNoteRepositoryInterface;
use Illuminate\Database\Eloquent\Collection;

class QuickNoteRepository implements QuickNoteRepositoryInterface
{
    public function visibleToUser(int $businessId, int $userId): Collection
    {
        return QuickNote::query()
            ->where('business_id', $businessId)
            ->where(function ($query) use ($userId) {
                $query->where('user_id', $userId)
                    ->orWhere('is_shared', true);
            })
            ->orderByDesc('updated_at')
            ->get();
    }

    public function ownedByUser(int $businessId, int $userId, int $noteId): ?QuickNote
    {
        return QuickNote::query()
            ->where('business_id', $businessId)
            ->where('user_id', $userId)
            ->whereKey($noteId)
            ->first();
    }

    public function findByClientUuid(int $businessId, string $clientUuid): ?QuickNote
    {
        return QuickNote::query()
            ->where('business_id', $businessId)
            ->where('client_uuid', $clientUuid)
            ->first();
    }

    public function create(array $data): QuickNote
    {
        return QuickNote::create($data);
    }

    public function update(QuickNote $note, array $data): QuickNote
    {
        $note->update($data);

        return $note->fresh();
    }

    public function delete(QuickNote $note): bool
    {
        return $note->delete();
    }
}
