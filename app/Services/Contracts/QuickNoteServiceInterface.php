<?php

namespace App\Services\Contracts;

use App\Models\QuickNote;
use Illuminate\Database\Eloquent\Collection;

interface QuickNoteServiceInterface
{
    public function visibleNotes(int $businessId, int $userId): Collection;

    public function getOwned(int $businessId, int $userId, int $noteId): ?QuickNote;

    /** Feature available on personal + business accounts; storefront buyers are excluded. */
    public function canUseFeature(\App\Models\User $user): bool;

    /** Sharing is available to business accounts only (owner + staff). */
    public function canShare(\App\Models\User $user): bool;

    public function create(int $businessId, int $userId, array $data): QuickNote;

    public function update(int $businessId, int $userId, int $noteId, array $data): QuickNote;

    public function delete(int $businessId, int $userId, int $noteId): bool;
}
