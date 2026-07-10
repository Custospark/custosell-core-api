<?php

declare(strict_types=1);

namespace App\Services\Documents;

use App\Models\Document;
use App\Models\DocumentFolder;
use App\Models\User;
use App\Services\ModuleAccessService;
use Illuminate\Support\Collection;

class DocumentAccessService
{
    public const VISIBILITIES = ['inherit', 'all_staff', 'selected_staff', 'owner_only'];

    public const FOLDER_VISIBILITIES = ['inherit', 'all_staff', 'selected_staff', 'owner_only'];

    public const ROLES = ['viewer', 'contributor', 'manager'];

    /** @var array<string, int> */
    private const ROLE_RANK = [
        'viewer' => 1,
        'contributor' => 2,
        'manager' => 3,
    ];

    public function __construct(
        protected ModuleAccessService $moduleAccess,
    ) {}

    public function isOwner(User $user): bool
    {
        return $this->moduleAccess->isBusinessOwner($user);
    }

    public function hasDocumentsModule(User $user): bool
    {
        return $this->moduleAccess->canAccess($user, 'documents');
    }

    public function assertHasDocumentsModule(User $user): void
    {
        if (! $this->hasDocumentsModule($user) && ! $this->isOwner($user)) {
            abort(403, 'You do not have access to Documents.');
        }
    }

    /** @return array{visibility: string, source_folder: DocumentFolder|null, source_document: Document|null, members: Collection<int, User>} */
    public function resolveEffectiveAcl(DocumentFolder|Document $resource): array
    {
        if ($resource instanceof Document) {
            if ($resource->visibility !== 'inherit') {
                return [
                    'visibility' => $resource->visibility,
                    'source_folder' => null,
                    'source_document' => $resource,
                    'members' => $resource->relationLoaded('members')
                        ? $resource->members
                        : $resource->members()->get(),
                ];
            }

            if ($resource->folder_id === null) {
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

    /** @return array{visibility: string, source_folder: DocumentFolder|null, source_document: Document|null, members: Collection<int, User>} */
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
                    'members' => $current->relationLoaded('members')
                        ? $current->members
                        : $current->members()->get(),
                ];
            }

            if ($current->parent_id === null) {
                break;
            }

            $current = $current->relationLoaded('parent') && $current->parent
                ? $current->parent
                : DocumentFolder::query()->find($current->parent_id);
        }

        return $this->defaultAcl();
    }

    /** @return array{visibility: string, source_folder: DocumentFolder|null, source_document: Document|null, members: Collection<int, User>} */
    protected function defaultAcl(): array
    {
        return [
            'visibility' => 'all_staff',
            'source_folder' => null,
            'source_document' => null,
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
            'owner_only' => null,
            default => null,
        };
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

    public function canContribute(User $user, DocumentFolder $folder): bool
    {
        if ($this->isOwner($user)) {
            return true;
        }

        $role = $this->roleFor($user, $folder);

        return $role !== null && self::ROLE_RANK[$role] >= self::ROLE_RANK['contributor'];
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

            return $role !== null && self::ROLE_RANK[$role] >= self::ROLE_RANK['contributor'];
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

    public function assertValidRole(?string $role): string
    {
        if ($role === null || ! in_array($role, self::ROLES, true)) {
            return 'viewer';
        }

        return $role;
    }

    /** @return list<array{id: int, name: string, avatar: string|null}> */
    public function listAccessibleMembers(int $businessId): array
    {
        return User::query()
            ->where('business_id', $businessId)
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name', 'avatar'])
            ->map(fn (User $member) => [
                'id' => (int) $member->id,
                'name' => $member->name,
                'avatar' => $member->avatar,
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
                && self::ROLE_RANK[$this->roleFor($user, $resource) ?? 'viewer'] >= self::ROLE_RANK['contributor']));
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
