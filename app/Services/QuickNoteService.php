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
        $data['is_pinned'] = isset($data['is_pinned']) && $data['is_pinned'] === true;
        $data['sort_order'] = (int) ($data['sort_order'] ?? $this->nextSortOrder($businessId, $userId));

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

        if (array_key_exists('is_pinned', $data)) {
            $data['is_pinned'] = $data['is_pinned'] === true;
        }

        if (array_key_exists('sort_order', $data)) {
            $data['sort_order'] = (int) $data['sort_order'];
        }

        return $this->quickNoteRepository->update($note, $data);
    }

    /** Persist a full custom ordering of the user's notes (drag-and-drop reorder). */
    public function reorder(int $businessId, int $userId, array $orderedIds): void
    {
        foreach (array_values($orderedIds) as $index => $noteId) {
            $note = $this->quickNoteRepository->ownedByUser($businessId, $userId, (int) $noteId);

            if ($note) {
                $this->quickNoteRepository->update($note, ['sort_order' => $index]);
            }
        }
    }

    /** Rename a tag across the user's own notes. */
    public function renameTag(int $businessId, int $userId, string $oldTag, string $newTag): int
    {
        return $this->quickNoteRepository->renameTag($businessId, $userId, $oldTag, $newTag);
    }

    /** Remove a tag from the user's own notes. */
    public function removeTag(int $businessId, int $userId, string $tag): int
    {
        return $this->quickNoteRepository->removeTag($businessId, $userId, $tag);
    }

    protected function nextSortOrder(int $businessId, int $userId): int
    {
        return QuickNote::query()
            ->where('business_id', $businessId)
            ->where('user_id', $userId)
            ->max('sort_order') + 1;
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
