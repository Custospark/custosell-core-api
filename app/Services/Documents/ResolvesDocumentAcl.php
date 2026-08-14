<?php

declare(strict_types=1);

namespace App\Services\Documents;

use App\Models\User;
use App\Services\ModuleAccessService;
use Illuminate\Support\Collection;

trait ResolvesDocumentAcl
{
    public const ROLES = ['viewer', 'contributor', 'manager'];

    /** @var array<string, int> */
    private const ROLE_RANK = [
        'viewer' => 1,
        'contributor' => 2,
        'manager' => 3,
    ];

    abstract protected function moduleAccessService(): ModuleAccessService;

    public function isOwner(User $user): bool
    {
        return $this->moduleAccessService()->isBusinessOwner($user);
    }

    public function hasDocumentsModule(User $user): bool
    {
        return $this->moduleAccessService()->canAccess($user, 'documents');
    }

    public function assertHasDocumentsModule(User $user): void
    {
        if (! $this->hasDocumentsModule($user) && ! $this->isOwner($user)) {
            abort(403, 'You do not have access to Documents.');
        }
    }

    /** @param  Collection<int, User>  $members */
    protected function memberRole(User $user, Collection $members): ?string
    {
        $member = $members->first(fn (User $item) => (int) $item->id === (int) $user->id);
        if ($member === null) {
            return null;
        }

        $role = $member->pivot->role ?? 'viewer';

        return in_array($role, self::ROLES, true) ? $role : 'viewer';
    }

    protected function roleRank(string $role): int
    {
        return self::ROLE_RANK[$role] ?? self::ROLE_RANK['viewer'];
    }

    public function assertValidRole(?string $role): string
    {
        if ($role === null || ! in_array($role, self::ROLES, true)) {
            return 'viewer';
        }

        return $role;
    }

    /** @param  list<int>  $memberUserIds
     * @return list<int>
     */
    protected function filterValidMemberIds(int $businessId, array $memberUserIds): array
    {
        $ids = collect($memberUserIds)
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->filter(fn (int $id) => $id > 0)
            ->values();

        $allowed = User::query()
            ->where('business_id', $businessId)
            ->whereIn('id', $ids)
            ->where('is_active', true)
            ->pluck('id')
            ->map(fn ($id) => (int) $id);

        $filtered = $ids->intersect($allowed)->values();

        if ($filtered->isEmpty()) {
            abort(422, 'Select at least one active team member.');
        }

        return $filtered->all();
    }
}
