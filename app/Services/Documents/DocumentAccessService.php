<?php

declare(strict_types=1);

namespace App\Services\Documents;

use App\Models\Document;
use App\Models\DocumentCabinet;
use App\Models\DocumentFolder;
use App\Models\User;
use App\Services\ModuleAccessService;
use Illuminate\Support\Collection;

class DocumentAccessService
{
    use ResolvesDocumentAcl;

    public const VISIBILITIES = ['inherit', 'all_staff', 'selected_staff', 'owner_only'];

    public const FOLDER_VISIBILITIES = ['inherit', 'all_staff', 'selected_staff', 'owner_only'];

    public function __construct(
        protected ModuleAccessService $moduleAccess,
        protected DocumentCabinetAccess $cabinetAccess,
    ) {}

    protected function moduleAccessService(): ModuleAccessService
    {
        return $this->moduleAccess;
    }

    /** @return array{visibility: string, source_folder: DocumentFolder|null, source_document: Document|null, source_cabinet: DocumentCabinet|null, members: Collection<int, User>} */
    public function resolveEffectiveAcl(DocumentFolder|Document $resource): array
    {
        if ($resource instanceof Document) {
            if ($resource->visibility !== 'inherit') {
                return [
                    'visibility' => $resource->visibility,
                    'source_folder' => null,
                    'source_document' => $resource,
                    'source_cabinet' => null,
                    'members' => $resource->relationLoaded('members')
                        ? $resource->members
                        : $resource->members()->get(),
                ];
            }

            if ($resource->folder_id === null) {
                if ($resource->cabinet_id !== null) {
                    return $this->cabinetAccess->resolveAcl($this->cabinetAccess->loadCabinet((int) $resource->cabinet_id));
                }

                return $this->defaultAcl();
            }

            $folder = $resource->relationLoaded('folder') && $resource->folder
                ? $resource->folder
                : DocumentFolder::query()->find($resource->folder_id);

            if ($folder === null) {
                return $this->defaultAcl();
            }

            return $this->resolveFolderEffectiveAcl($folder);
        }

        return $this->resolveFolderEffectiveAcl($resource);
    }

    /** @return array{visibility: string, source_folder: DocumentFolder|null, source_document: Document|null, source_cabinet: DocumentCabinet|null, members: Collection<int, User>} */
    protected function resolveFolderEffectiveAcl(DocumentFolder $folder): array
    {
        $current = $folder;
        $visited = [];

        while ($current !== null) {
            if (in_array($current->id, $visited, true)) {
                break;
            }
            $visited[] = $current->id;

            if ($current->visibility !== 'inherit') {
                return [
                    'visibility' => $current->visibility,
                    'source_folder' => $current,
                    'source_document' => null,
                    'source_cabinet' => null,
                    'members' => $current->relationLoaded('members')
                        ? $current->members
                        : $current->members()->get(),
                ];
            }

            if ($current->parent_id === null) {
                if ($current->cabinet_id !== null) {
                    return $this->cabinetAccess->resolveAcl($this->cabinetAccess->loadCabinet((int) $current->cabinet_id));
                }

                break;
            }

            $current = $current->relationLoaded('parent') && $current->parent
                ? $current->parent
                : DocumentFolder::query()->find($current->parent_id);

            if ($current === null) {
                break;
            }
        }

        return $this->defaultAcl();
    }

    /** @return array{visibility: string, source_folder: DocumentFolder|null, source_document: Document|null, source_cabinet: DocumentCabinet|null, members: Collection<int, User>} */
    protected function defaultAcl(): array
    {
        return [
            'visibility' => 'all_staff',
            'source_folder' => null,
            'source_document' => null,
            'source_cabinet' => null,
            'members' => collect(),
        ];
    }

    public function canView(User $user, DocumentFolder|Document $resource): bool
    {
        if ($this->isOwner($user)) {
            return true;
        }

        if (! $this->hasDocumentsModule($user) || ! $user->is_active) {
            return false;
        }

        if ($resource instanceof Document && (int) $resource->uploaded_by === (int) $user->id) {
            return true;
        }

        if ($resource instanceof DocumentFolder && (int) $resource->created_by === (int) $user->id) {
            return true;
        }

        $acl = $this->resolveEffectiveAcl($resource);

        return match ($acl['visibility']) {
            'all_staff' => true,
            'owner_only' => false,
            'selected_staff' => $acl['members']->contains(fn (User $member) => (int) $member->id === (int) $user->id),
            default => false,
        };
    }

    public function roleFor(User $user, DocumentFolder|Document $resource): ?string
    {
        if ($this->isOwner($user)) {
            return 'manager';
        }

        if (! $this->canView($user, $resource)) {
            return null;
        }

        $acl = $this->resolveEffectiveAcl($resource);

        return match ($acl['visibility']) {
            'all_staff' => 'contributor',
            'selected_staff' => $this->memberRole($user, $acl['members']),
            'owner_only' => (
                ($resource instanceof DocumentFolder && (int) $resource->created_by === (int) $user->id)
                || ($resource instanceof Document && (int) $resource->uploaded_by === (int) $user->id)
            ) ? 'manager' : null,
            default => null,
        };
    }

    public function canContribute(User $user, DocumentFolder $folder): bool
    {
        if ($this->isOwner($user)) {
            return true;
        }

        $role = $this->roleFor($user, $folder);

        return $role !== null && $this->roleRank($role) >= $this->roleRank('contributor');
    }

    public function canManage(User $user, DocumentFolder|Document $resource): bool
    {
        if ($this->isOwner($user)) {
            return true;
        }

        $role = $this->roleFor($user, $resource);

        return $role === 'manager';
    }

    public function canEditDocument(User $user, Document $document): bool
    {
        if ($this->canManage($user, $document)) {
            return true;
        }

        if ((int) $document->uploaded_by === (int) $user->id) {
            $role = $this->roleFor($user, $document);

            return $role !== null && $this->roleRank($role) >= $this->roleRank('contributor');
        }

        return false;
    }

    public function canDeleteDocument(User $user, Document $document): bool
    {
        return $this->canEditDocument($user, $document);
    }

    public function assertCanView(User $user, DocumentFolder|Document $resource): void
    {
        if (! $this->canView($user, $resource)) {
            abort(403, 'You do not have access to this item.');
        }
    }

    public function assertCanContributeToFolder(User $user, DocumentFolder $folder): void
    {
        if (! $this->canContribute($user, $folder)) {
            abort(403, 'You cannot upload to this folder.');
        }
    }

    public function assertCanManage(User $user, DocumentFolder|Document $resource): void
    {
        if (! $this->canManage($user, $resource)) {
            abort(403, 'You cannot manage this item.');
        }
    }

    /** @param  list<int>  $memberUserIds
     * @param  array<int, string>|null  $memberRoles
     */
    public function assertValidVisibility(string $visibility, array $memberUserIds, bool $allowInherit = true): void
    {
        $allowed = $allowInherit ? self::VISIBILITIES : array_values(array_filter(
            self::FOLDER_VISIBILITIES,
            fn (string $value) => $value !== 'inherit',
        ));

        if (! in_array($visibility, $allowed, true)) {
            abort(422, 'Invalid visibility.');
        }

        if ($visibility === 'selected_staff' && count($memberUserIds) === 0) {
            abort(422, 'Select at least one team member for selected staff visibility.');
        }
    }

    /** @return list<array{id: int, name: string, avatar: string|null}> */
    public function listAccessibleMembers(int $businessId): array
    {
        return User::query()
            ->where('business_id', $businessId)
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name', 'avatar', 'email'])
            ->map(fn (User $member) => [
                'id' => (int) $member->id,
                'name' => $member->name,
                'avatar' => $member->avatar,
                'email' => $member->email,
            ])
            ->values()
            ->all();
    }

    /** @return array<string, mixed> */
    public function permissionFlags(User $user, DocumentFolder|Document $resource): array
    {
        $acl = $this->resolveEffectiveAcl($resource);
        $canView = $this->canView($user, $resource);
        $canManage = $this->canManage($user, $resource);
        $canContribute = $resource instanceof DocumentFolder
            ? $this->canContribute($user, $resource)
            : ($canManage || ($this->roleFor($user, $resource) !== null
                && $this->roleRank($this->roleFor($user, $resource) ?? 'viewer') >= $this->roleRank('contributor')));
        $canEdit = $resource instanceof Document
            ? $this->canEditDocument($user, $resource)
            : $canManage;
        $canDelete = $resource instanceof Document
            ? $this->canDeleteDocument($user, $resource)
            : $canManage;

        return [
            'can_view' => $canView,
            'can_contribute' => $canContribute,
            'can_edit' => $canEdit,
            'can_delete' => $canDelete,
            'can_manage' => $canManage,
            'effective_visibility' => $acl['visibility'],
            'inherited_from_folder_id' => $acl['source_folder']?->id,
            'inherited_from_cabinet_id' => $acl['source_cabinet']?->id ?? null,
        ];
    }

    /** @param  list<int>  $memberUserIds
     * @param  array<int, string>  $memberRoles
     */
    public function syncFolderMembers(DocumentFolder $folder, int $businessId, array $memberUserIds, array $memberRoles = []): void
    {
        if ($folder->visibility !== 'selected_staff') {
            $folder->memberLinks()->delete();

            return;
        }

        $validIds = $this->filterValidMemberIds($businessId, $memberUserIds);
        $folder->memberLinks()->whereNotIn('user_id', $validIds)->delete();

        foreach ($validIds as $userId) {
            $folder->memberLinks()->updateOrCreate(
                ['user_id' => $userId],
                ['role' => $this->assertValidRole($memberRoles[$userId] ?? null)],
            );
        }
    }

    /** @param  list<int>  $memberUserIds
     * @param  array<int, string>  $memberRoles
     */
    public function syncDocumentMembers(Document $document, int $businessId, array $memberUserIds, array $memberRoles = []): void
    {
        if ($document->visibility !== 'selected_staff') {
            $document->memberLinks()->delete();

            return;
        }

        $validIds = $this->filterValidMemberIds($businessId, $memberUserIds);
        $document->memberLinks()->whereNotIn('user_id', $validIds)->delete();

        foreach ($validIds as $userId) {
            $document->memberLinks()->updateOrCreate(
                ['user_id' => $userId],
                ['role' => $this->assertValidRole($memberRoles[$userId] ?? null)],
            );
        }
    }

    // Cabinet delegation (implementation lives in DocumentCabinetAccess).
    public function resolveCabinetAcl(DocumentCabinet $cabinet): array
    {
        return $this->cabinetAccess->resolveAcl($cabinet);
    }

    public function canViewCabinet(User $user, DocumentCabinet $cabinet): bool
    {
        return $this->cabinetAccess->canViewCabinet($user, $cabinet);
    }

    public function roleForCabinet(User $user, DocumentCabinet $cabinet): ?string
    {
        return $this->cabinetAccess->roleForCabinet($user, $cabinet);
    }

    public function canContributeToCabinet(User $user, DocumentCabinet $cabinet): bool
    {
        return $this->cabinetAccess->canContributeToCabinet($user, $cabinet);
    }

    public function canManageCabinet(User $user, DocumentCabinet $cabinet): bool
    {
        return $this->cabinetAccess->canManageCabinet($user, $cabinet);
    }

    public function assertCanViewCabinet(User $user, DocumentCabinet $cabinet): void
    {
        $this->cabinetAccess->assertCanViewCabinet($user, $cabinet);
    }

    public function assertCanContributeToCabinet(User $user, DocumentCabinet $cabinet): void
    {
        $this->cabinetAccess->assertCanContributeToCabinet($user, $cabinet);
    }

    public function assertCanManageCabinet(User $user, DocumentCabinet $cabinet): void
    {
        $this->cabinetAccess->assertCanManageCabinet($user, $cabinet);
    }

    /** @return array<string, mixed> */
    public function cabinetPermissionFlags(User $user, DocumentCabinet $cabinet): array
    {
        return $this->cabinetAccess->permissionFlags($user, $cabinet);
    }

    /** @param  list<int>  $memberUserIds
     * @param  array<int, string>  $memberRoles
     */
    public function syncCabinetMembers(DocumentCabinet $cabinet, int $businessId, array $memberUserIds, array $memberRoles = []): void
    {
        $this->cabinetAccess->syncMembers($cabinet, $businessId, $memberUserIds, $memberRoles);
    }
}
