<?php

namespace App\Repositories\Contracts;

use App\Models\QuickNote;
use Illuminate\Database\Eloquent\Collection;

interface QuickNoteRepositoryInterface
{
    /** Notes visible to a user: their own plus notes shared within the business. */
    public function visibleToUser(int $businessId, int $userId): Collection;

    /** Notes owned by a user - private scope for updates, deletes, and show. */
    public function ownedByUser(int $businessId, int $userId, int $noteId): ?QuickNote;

    /** Resolve a note by its client-generated UUID for offline sync idempotency. */
    public function findByClientUuid(int $businessId, string $clientUuid): ?QuickNote;

    public function create(array $data): QuickNote;

    public function update(QuickNote $note, array $data): QuickNote;

    public function delete(QuickNote $note): bool;
}
