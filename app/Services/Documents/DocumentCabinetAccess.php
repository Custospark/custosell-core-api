<?php

declare(strict_types=1);

namespace App\Services\Documents;

use App\Models\DocumentCabinet;
use App\Models\User;
use App\Services\ModuleAccessService;
use Illuminate\Support\Collection;

class DocumentCabinetAccess
{
    use ResolvesDocumentAcl;

    public function __construct(
        protected ModuleAccessService $moduleAccess,
    ) {}

    protected function moduleAccessService(): ModuleAccessService
    {
        return $this->moduleAccess;
    }

    /** @return array{visibility: string, source_folder: null, source_document: null, source_cabinet: DocumentCabinet|null, members: Collection<int, User>} */
    public function resolveAcl(DocumentCabinet $cabinet): array
    {
        return [
            'visibility' => $cabinet->visibility,
            'source_folder' => null,
            'source_document' => null,
            'source_cabinet' => $cabinet,
            'members' => $cabinet->relationLoaded('members')
                ? $cabinet->members
                : $cabinet->members()->get(),
        ];
    }

    public function loadCabinet(int $cabinetId): DocumentCabinet
    {
        return DocumentCabinet::query()
            ->with(['members:id,name,avatar'])
            ->findOrFail($cabinetId);
    }

    public function canViewCabinet(User $user, DocumentCabinet $cabinet): bool
    {
        if ($this->isOwner($user)) {
            return true;
        }

        if (! $this->hasDocumentsModule($user) || ! $user->is_active) {
            return false;
        }

        if ((int) $cabinet->created_by === (int) $user->id) {
            return true;
        }

        return match ($cabinet->visibility) {
            'all_staff' => true,
            'owner_only' => false,
            'selected_staff' => $cabinet->relationLoaded('members')
                ? $cabinet->members->contains(fn (User $member) => (int) $member->id === (int) $user->id)
                : $cabinet->members()->where('users.id', $user->id)->exists(),
            default => false,
        };
    }

    public function roleForCabinet(User $user, DocumentCabinet $cabinet): ?string
    {
        if ($this->isOwner($user) || (int) $cabinet->created_by === (int) $user->id) {
            return 'manager';
        }

        if (! $this->canViewCabinet($user, $cabinet)) {
            return null;
        }

        return match ($cabinet->visibility) {
            'all_staff' => 'contributor',
            'selected_staff' => $this->memberRole(
                $user,
                $cabinet->relationLoaded('members') ? $cabinet->members : $cabinet->members()->get(),
            ),
            'owner_only' => null,
            default => null,
        };
    }

    public function canContributeToCabinet(User $user, DocumentCabinet $cabinet): bool
    {
        if ($this->isOwner($user) || (int) $cabinet->created_by === (int) $user->id) {
            return true;
        }

        $role = $this->roleForCabinet($user, $cabinet);

        return $role !== null && $this->roleRank($role) >= $this->roleRank('contributor');
    }

    public function canManageCabinet(User $user, DocumentCabinet $cabinet): bool
    {
        if ($this->isOwner($user) || (int) $cabinet->created_by === (int) $user->id) {
            return true;
        }

        return $this->roleForCabinet($user, $cabinet) === 'manager';
    }

    public function assertCanViewCabinet(User $user, DocumentCabinet $cabinet): void
    {
        if (! $this->canViewCabinet($user, $cabinet)) {
            abort(403, 'You do not have access to this cabinet.');
        }
    }

    public function assertCanContributeToCabinet(User $user, DocumentCabinet $cabinet): void
    {
        if (! $this->canContributeToCabinet($user, $cabinet)) {
            abort(403, 'You cannot add content to this cabinet.');
        }
    }

    public function assertCanManageCabinet(User $user, DocumentCabinet $cabinet): void
    {
        if (! $this->canManageCabinet($user, $cabinet)) {
            abort(403, 'You cannot manage this cabinet.');
        }
    }

    /** @return array<string, mixed> */
    public function permissionFlags(User $user, DocumentCabinet $cabinet): array
    {
        $role = $this->roleForCabinet($user, $cabinet);
        $canView = $this->canViewCabinet($user, $cabinet);
        $canManage = $this->canManageCabinet($user, $cabinet);
        $canContribute = $this->canContributeToCabinet($user, $cabinet);

        return [
            'can_view' => $canView,
            'can_contribute' => $canContribute,
            'can_edit' => $canManage,
            'can_delete' => $canManage,
            'can_manage' => $canManage,
            'effective_visibility' => $cabinet->visibility,
            'inherited_from_folder_id' => null,
            'inherited_from_cabinet_id' => null,
            'current_member_role' => $role,
        ];
    }

    /** @param  list<int>  $memberUserIds
     * @param  array<int, string>  $memberRoles
     */
    public function syncMembers(DocumentCabinet $cabinet, int $businessId, array $memberUserIds, array $memberRoles = []): void
    {
        if ($cabinet->visibility !== 'selected_staff') {
            $cabinet->memberLinks()->delete();

            return;
        }

        $validIds = $this->filterValidMemberIds($businessId, $memberUserIds);
        $cabinet->memberLinks()->whereNotIn('user_id', $validIds)->delete();

        foreach ($validIds as $userId) {
            $cabinet->memberLinks()->updateOrCreate(
                ['user_id' => $userId],
                ['role' => $this->assertValidRole($memberRoles[$userId] ?? null)],
            );
        }
    }
}
