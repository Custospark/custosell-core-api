<?php

namespace App\Services;

use App\Models\QuickNote;
use App\Models\User;
use App\Repositories\Contracts\QuickNoteRepositoryInterface;
use App\Services\Contracts\QuickNoteServiceInterface;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\ModelNotFoundException;

class QuickNoteService implements QuickNoteServiceInterface
{
    public function __construct(
        protected QuickNoteRepositoryInterface $quickNoteRepository,
    ) {}

    public function visibleNotes(int $businessId, int $userId): Collection
    {
        return $this->quickNoteRepository->visibleToUser($businessId, $userId);
    }

    public function getOwned(int $businessId, int $userId, int $noteId): ?QuickNote
    {
        return $this->quickNoteRepository->ownedByUser($businessId, $userId, $noteId);
    }

    public function canUseFeature(User $user): bool
    {
        return $user->account_type !== 'storefront_buyer';
    }

    public function canShare(User $user): bool
    {
        return $user->account_type !== 'personal'
            && $user->account_type !== 'storefront_buyer';
    }

    public function create(int $businessId, int $userId, array $data): QuickNote
    {
        if (! empty($data['client_uuid'])) {
            $existing = $this->quickNoteRepository->findByClientUuid($businessId, $data['client_uuid']);

            if ($existing) {
                return $existing;
            }
        }

        $data['business_id'] = $businessId;
        $data['user_id'] = $userId;
        $data['is_shared'] = isset($data['is_shared']) && $data['is_shared'] === true;

        return $this->quickNoteRepository->create($data);
    }

    public function update(int $businessId, int $userId, int $noteId, array $data): QuickNote
    {
        $note = $this->quickNoteRepository->ownedByUser($businessId, $userId, $noteId);

        if (! $note) {
            throw (new ModelNotFoundException)->setModel(QuickNote::class, $noteId);
        }

        if (array_key_exists('is_shared', $data)) {
            $data['is_shared'] = $data['is_shared'] === true;
        }

        return $this->quickNoteRepository->update($note, $data);
    }

    public function delete(int $businessId, int $userId, int $noteId): bool
    {
        $note = $this->quickNoteRepository->ownedByUser($businessId, $userId, $noteId);

        if (! $note) {
            throw (new ModelNotFoundException)->setModel(QuickNote::class, $noteId);
        }

        return $this->quickNoteRepository->delete($note);
    }
}
