<?php

declare(strict_types=1);

namespace App\Services\Documents;

use App\Models\Document;
use App\Models\DocumentFolder;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class DocumentFolderService
{
    public function __construct(
        protected DocumentAccessService $access,
        protected DocumentActivityService $activity,
    ) {}

    /** @return list<array<string, mixed>> */
    public function tree(int $businessId, User $user): array
    {
        $folders = DocumentFolder::query()
            ->where('business_id', $businessId)
            ->with([
                'creator:id,name,avatar',
                'members:id,name,avatar',
                'parent:id,parent_id,visibility,name',
            ])
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();

        $byParent = $folders->groupBy(fn (DocumentFolder $folder) => (string) ($folder->parent_id ?? 'root'));

        return $this->buildTreeNodes($byParent, 'root', $user);
    }

    /**
     * @param  Collection<string, Collection<int, DocumentFolder>>  $byParent
     * @return list<array<string, mixed>>
     */
    protected function buildTreeNodes(Collection $byParent, string $parentKey, User $user): array
    {
        $nodes = [];

        foreach ($byParent->get($parentKey, collect()) as $folder) {
            if (! $this->access->canView($user, $folder)) {
                continue;
            }

            $nodes[] = array_merge(
                $this->serializeFolder($folder, $user, false),
                [
                    'children' => $this->buildTreeNodes($byParent, (string) $folder->id, $user),
                ],
            );
        }

        return $nodes;
    }

    /** @return array<string, mixed> */
    public function show(int $businessId, User $user, int $folderId): array
    {
        $folder = $this->findFolder($businessId, $folderId);
        $this->access->assertCanView($user, $folder);

        return array_merge(
            $this->serializeFolder($folder, $user, true),
            ['breadcrumbs' => $this->breadcrumbs($folder)],
        );
    }

    /** @return array{data: list<array<string, mixed>>, meta: array<string, int>} */
    public function listChildren(
        int $businessId,
        User $user,
        ?int $parentId,
        int $page = 1,
        int $perPage = 100,
    ): array {
        $perPage = min(max($perPage, 1), 200);
        $page = max($page, 1);

        $paginator = DocumentFolder::query()
            ->where('business_id', $businessId)
            ->where('parent_id', $parentId)
            ->with(['creator:id,name,avatar', 'members:id,name,avatar'])
            ->orderBy('sort_order')
            ->orderBy('name')
            ->paginate($perPage, ['*'], 'page', $page);

        $data = $paginator->getCollection()
            ->filter(fn (DocumentFolder $item) => $this->access->canView($user, $item))
            ->map(function (DocumentFolder $item) use ($user) {
                $serialized = $this->serializeFolder($item, $user, true);
                $serialized['has_children'] = DocumentFolder::query()
                    ->where('business_id', $item->business_id)
                    ->where('parent_id', $item->id)
                    ->exists();

                return $serialized;
            })
            ->values()
            ->all();

        return [
            'data' => $data,
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
            ],
        ];
    }

    /** @return array{folder: array<string, mixed>, breadcrumbs: list<array{id: int, name: string}>, folders: list<array<string, mixed>>, documents: list<array<string, mixed>>, documents_meta: array<string, int>} */
    public function contents(
        int $businessId,
        User $user,
        int $folderId,
        DocumentService $documentService,
        int $page = 1,
        int $perPage = 50,
    ): array {
        $folder = $this->findFolder($businessId, $folderId);
        $this->access->assertCanView($user, $folder);

        $children = $this->listChildren($businessId, $user, $folder->id, 1, min($perPage, 100));
        $documentPage = $documentService->listPaginated(
            $businessId,
            $user,
            null,
            $folder->id,
            null,
            null,
            null,
            null,
            null,
            false,
            $page,
            $perPage,
        );

        return [
            'folder' => $this->serializeFolder($folder, $user, true),
            'breadcrumbs' => $this->breadcrumbs($folder),
            'folders' => $children['data'],
            'folders_meta' => $children['meta'],
            'documents' => $documentPage['data'],
            'documents_meta' => $documentPage['meta'],
        ];
    }

    /** @param  list<int>  $memberUserIds
     * @param  array<int, string>  $memberRoles
     * @return array<string, mixed>
     */
    public function create(
        int $businessId,
        User $user,
        string $name,
        ?string $description,
        string $visibility,
        ?int $parentId,
        array $memberUserIds = [],
        array $memberRoles = [],
    ): array {
        $this->access->assertHasDocumentsModule($user);

        $depth = 1;
        $parent = null;

        if ($parentId !== null) {
            $parent = $this->findFolder($businessId, $parentId);
            $this->access->assertCanContributeToFolder($user, $parent);
            $depth = $parent->depth + 1;
            if ($depth > (int) config('documents.max_depth', 5)) {
                abort(422, 'Maximum folder depth of '.config('documents.max_depth', 5).' reached.');
            }
        } elseif (! $this->access->hasDocumentsModule($user)) {
            abort(403, 'You do not have access to create folders.');
        }

        if ($parentId === null) {
            $this->access->assertValidVisibility($visibility, $memberUserIds, false);
        } else {
            $this->access->assertValidVisibility($visibility, $memberUserIds, true);
        }

        $folder = DocumentFolder::create([
            'business_id' => $businessId,
            'parent_id' => $parentId,
            'name' => trim($name),
            'description' => $description,
            'visibility' => $visibility,
            'depth' => $depth,
            'created_by' => $user->id,
        ]);

        $this->access->syncFolderMembers($folder, $businessId, $memberUserIds, $memberRoles);

        $this->activity->record(
            $businessId,
            $user,
            'folder_created',
            'folder',
            $folder->id,
            $folder->name,
            $folder->parent_id,
        );

        return $this->serializeFolder($this->reloadFolder($folder), $user, true);
    }

    /** @param  list<int>|null  $memberUserIds
     * @param  array<int, string>|null  $memberRoles
     * @return array<string, mixed>
     */
    public function update(
        int $businessId,
        User $user,
        int $folderId,
        ?string $name = null,
        ?string $description = null,
        ?string $visibility = null,
        ?int $parentId = null,
        ?array $memberUserIds = null,
        ?array $memberRoles = null,
        ?int $sortOrder = null,
        ?string $coverColor = null,
    ): array {
        $folder = $this->findFolder($businessId, $folderId);
        $this->access->assertCanManage($user, $folder);

        $previousName = $folder->name;
        $previousParentId = $folder->parent_id;
        $previousVisibility = $folder->visibility;
        $hadMemberUpdate = $memberUserIds !== null;
        $hadColorUpdate = $coverColor !== null;

        if ($name !== null) {
            $folder->name = trim($name);
        }
        if ($description !== null) {
            $folder->description = $description;
        }
        if ($sortOrder !== null) {
            $folder->sort_order = $sortOrder;
        }
        if ($coverColor !== null) {
            $folder->cover_color = $coverColor === '' ? null : $coverColor;
        }

        if ($visibility !== null) {
            $this->access->assertValidVisibility(
                $visibility,
                $memberUserIds ?? $folder->members()->pluck('users.id')->map(fn ($id) => (int) $id)->all(),
                $folder->parent_id !== null,
            );
            $folder->visibility = $visibility;
        }

        if ($parentId !== null && $parentId !== $folder->parent_id) {
            $this->moveFolder($businessId, $folder, $parentId);
        }

        $folder->save();

        if ($memberUserIds !== null) {
            $this->access->syncFolderMembers(
                $folder,
                $businessId,
                $memberUserIds,
                $memberRoles ?? [],
            );
        } elseif ($visibility !== null && $visibility !== 'selected_staff') {
            $folder->memberLinks()->delete();
        }

        $reloaded = $this->reloadFolder($folder);

        if ($name !== null && trim($name) !== $previousName) {
            $this->activity->record($businessId, $user, 'folder_renamed', 'folder', $folder->id, $folder->name, $folder->parent_id);
        }
        if ($parentId !== null && $parentId !== $previousParentId) {
            $this->activity->record($businessId, $user, 'folder_moved', 'folder', $folder->id, $folder->name, $folder->parent_id);
        }
        if (($visibility !== null && $visibility !== $previousVisibility) || $hadMemberUpdate) {
            $this->activity->record($businessId, $user, 'folder_access_changed', 'folder', $folder->id, $folder->name, $folder->parent_id);
        }
        if ($hadColorUpdate) {
            $this->activity->record($businessId, $user, 'folder_color_changed', 'folder', $folder->id, $folder->name, $folder->parent_id);
        }

        return $this->serializeFolder($reloaded, $user, true);
    }

    public function destroy(int $businessId, User $user, int $folderId): void
    {
        $folder = $this->findFolder($businessId, $folderId);
        $this->access->assertCanManage($user, $folder);

        $folderName = $folder->name;
        $folderParentId = $folder->parent_id;

        DB::transaction(function () use ($folder, $businessId): void {
            $this->cascadeDeleteFolder($folder, $businessId);
        });

        $this->activity->record($businessId, $user, 'folder_deleted', 'folder', null, $folderName, $folderParentId);
    }

    protected function cascadeDeleteFolder(DocumentFolder $folder, int $businessId): void
    {
        $children = DocumentFolder::query()
            ->where('business_id', $businessId)
            ->where('parent_id', $folder->id)
            ->get();

        foreach ($children as $child) {
            $this->cascadeDeleteFolder($child, $businessId);
        }

        $documents = Document::query()
            ->where('business_id', $businessId)
            ->where('folder_id', $folder->id)
            ->get();

        foreach ($documents as $document) {
            app(DocumentService::class)->deleteFileFromDisk($document);
            $document->memberLinks()->delete();
            $document->tags()->detach();
            $document->delete();
        }

        $folder->memberLinks()->delete();
        $folder->delete();
    }

    protected function moveFolder(int $businessId, DocumentFolder $folder, int $newParentId): void
    {
        if ($newParentId === $folder->id) {
            abort(422, 'A folder cannot be moved into itself.');
        }

        $newParent = $this->findFolder($businessId, $newParentId);
        if ($this->isDescendant($newParent, $folder->id)) {
            abort(422, 'A folder cannot be moved into its own subfolder.');
        }

        $newDepth = $newParent->depth + 1;
        $maxSubtreeDepth = $this->maxSubtreeDepth($folder);
        if ($newDepth + $maxSubtreeDepth - 1 > (int) config('documents.max_depth', 5)) {
            abort(422, 'Move would exceed maximum folder depth.');
        }

        $depthDelta = $newDepth - $folder->depth;
        $folder->parent_id = $newParent->id;
        $folder->depth = $newDepth;
        $this->applyDepthDelta($folder, $businessId, $depthDelta);
    }

    protected function isDescendant(DocumentFolder $candidate, int $ancestorId): bool
    {
        $current = $candidate;
        $visited = [];

        while ($current !== null) {
            if (in_array($current->id, $visited, true)) {
                return false;
            }
            $visited[] = $current->id;

            if ((int) $current->id === $ancestorId) {
                return true;
            }

            if ($current->parent_id === null) {
                return false;
            }

            $current = DocumentFolder::query()->find($current->parent_id);
        }

        return false;
    }

    protected function maxSubtreeDepth(DocumentFolder $folder): int
    {
        $children = DocumentFolder::query()->where('parent_id', $folder->id)->get();
        if ($children->isEmpty()) {
            return 1;
        }

        return 1 + $children->map(fn (DocumentFolder $child) => $this->maxSubtreeDepth($child))->max();
    }

    protected function applyDepthDelta(DocumentFolder $folder, int $businessId, int $delta): void
    {
        if ($delta === 0) {
            return;
        }

        $children = DocumentFolder::query()
            ->where('business_id', $businessId)
            ->where('parent_id', $folder->id)
            ->get();

        foreach ($children as $child) {
            $child->depth += $delta;
            $child->save();
            $this->applyDepthDelta($child, $businessId, $delta);
        }
    }

    /** @return list<array{id: int, name: string}> */
    protected function breadcrumbs(DocumentFolder $folder): array
    {
        $crumbs = [];
        $current = $folder;

        while ($current !== null) {
            array_unshift($crumbs, ['id' => $current->id, 'name' => $current->name]);
            if ($current->parent_id === null) {
                break;
            }
            $current = DocumentFolder::query()->find($current->parent_id);
        }

        return $crumbs;
    }

    protected function findFolder(int $businessId, int $folderId): DocumentFolder
    {
        return DocumentFolder::query()
            ->where('business_id', $businessId)
            ->whereKey($folderId)
            ->firstOrFail();
    }

    protected function reloadFolder(DocumentFolder $folder): DocumentFolder
    {
        return $folder->fresh([
            'creator:id,name,avatar',
            'members:id,name,avatar',
            'parent:id,parent_id,visibility,name',
        ]) ?? $folder;
    }

    /** @return array<string, mixed> */
    public function serializeFolder(DocumentFolder $folder, User $user, bool $withCounts): array
    {
        $flags = $this->access->permissionFlags($user, $folder);

        $payload = [
            'id' => $folder->id,
            'parent_id' => $folder->parent_id,
            'name' => $folder->name,
            'description' => $folder->description,
            'visibility' => $folder->visibility,
            'cover_color' => $folder->cover_color,
            'depth' => $folder->depth,
            'sort_order' => $folder->sort_order,
            'created_at' => $folder->created_at?->toISOString(),
            'updated_at' => $folder->updated_at?->toISOString(),
            'creator' => $folder->creator ? [
                'id' => $folder->creator->id,
                'name' => $folder->creator->name,
                'avatar' => $folder->creator->avatar,
            ] : null,
            'members' => $folder->members->map(fn ($member) => [
                'id' => $member->id,
                'name' => $member->name,
                'avatar' => $member->avatar,
                'role' => $member->pivot->role ?? 'viewer',
            ])->values()->all(),
            ...$flags,
        ];

        if ($withCounts) {
            $payload['subfolder_count'] = DocumentFolder::query()->where('parent_id', $folder->id)->count();
            $payload['document_count'] = Document::query()->where('folder_id', $folder->id)->count();
        }

        return $payload;
    }
}
