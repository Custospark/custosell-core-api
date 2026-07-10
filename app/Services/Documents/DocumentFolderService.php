<?php

declare(strict_types=1);

namespace App\Services\Documents;

use App\Models\Document;
use App\Models\DocumentCabinet;
use App\Models\DocumentFolder;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;
use ZipStream\OperationMode;
use ZipStream\ZipStream;

class DocumentFolderService
{
    public function __construct(
        protected DocumentAccessService $access,
        protected DocumentActivityService $activity,
    ) {}

    /** @return list<array<string, mixed>> */
    public function tree(int $businessId, User $user, ?int $cabinetId = null): array
    {
        $builder = DocumentFolder::query()
            ->where('business_id', $businessId)
            ->with([
                'creator:id,name,avatar',
                'members:id,name,avatar',
                'parent:id,parent_id,visibility,name',
            ])
            ->orderBy('sort_order')
            ->orderBy('name');

        if ($cabinetId !== null) {
            $cabinet = DocumentCabinet::query()
                ->where('business_id', $businessId)
                ->whereKey($cabinetId)
                ->firstOrFail();
            $this->access->assertCanViewCabinet($user, $cabinet);
            $builder->where('cabinet_id', $cabinetId);
        }

        $folders = $builder->get();

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
        int $cabinetId,
        ?int $parentId,
        int $page = 1,
        int $perPage = 100,
    ): array {
        $perPage = min(max($perPage, 1), 200);
        $page = max($page, 1);

        $cabinet = DocumentCabinet::query()
            ->where('business_id', $businessId)
            ->whereKey($cabinetId)
            ->firstOrFail();
        $this->access->assertCanViewCabinet($user, $cabinet);

        $paginator = DocumentFolder::query()
            ->where('business_id', $businessId)
            ->where('cabinet_id', $cabinetId)
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

        $children = $this->listChildren($businessId, $user, (int) $folder->cabinet_id, $folder->id, 1, min($perPage, 100));
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
            (int) $folder->cabinet_id,
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
        ?int $cabinetId = null,
    ): array {
        $this->access->assertHasDocumentsModule($user);

        $depth = 1;
        $parent = null;
        $resolvedCabinetId = $cabinetId;

        if ($parentId !== null) {
            $parent = $this->findFolder($businessId, $parentId);
            $this->access->assertCanContributeToFolder($user, $parent);
            $depth = $parent->depth + 1;
            $resolvedCabinetId = (int) $parent->cabinet_id;
            if ($depth > (int) config('documents.max_depth', 5)) {
                abort(422, 'Maximum folder depth of '.config('documents.max_depth', 5).' reached.');
            }
        } else {
            if ($resolvedCabinetId === null) {
                abort(422, 'Cabinet is required for root folders.');
            }
            $cabinet = DocumentCabinet::query()
                ->where('business_id', $businessId)
                ->whereKey($resolvedCabinetId)
                ->firstOrFail();
            $this->access->assertCanContributeToCabinet($user, $cabinet);
        }

        $allowInherit = $parentId !== null || $resolvedCabinetId !== null;
        $this->access->assertValidVisibility($visibility, $memberUserIds, $allowInherit);

        $folder = DocumentFolder::create([
            'business_id' => $businessId,
            'cabinet_id' => $resolvedCabinetId,
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
            $folder->cabinet_id,
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
            $this->activity->record($businessId, $user, 'folder_renamed', 'folder', $folder->id, $folder->name, $folder->parent_id, $folder->cabinet_id);
        }
        if ($parentId !== null && $parentId !== $previousParentId) {
            $this->activity->record($businessId, $user, 'folder_moved', 'folder', $folder->id, $folder->name, $folder->parent_id, $folder->cabinet_id);
        }
        if (($visibility !== null && $visibility !== $previousVisibility) || $hadMemberUpdate) {
            $this->activity->record($businessId, $user, 'folder_access_changed', 'folder', $folder->id, $folder->name, $folder->parent_id, $folder->cabinet_id);
        }
        if ($hadColorUpdate) {
            $this->activity->record($businessId, $user, 'folder_color_changed', 'folder', $folder->id, $folder->name, $folder->parent_id, $folder->cabinet_id);
        }

        return $this->serializeFolder($reloaded, $user, true);
    }

    public function destroy(int $businessId, User $user, int $folderId): void
    {
        $folder = $this->findFolder($businessId, $folderId);
        $this->access->assertCanManage($user, $folder);

        $folderName = $folder->name;
        $folderParentId = $folder->parent_id;
        $cabinetId = $folder->cabinet_id;

        DB::transaction(function () use ($folder, $businessId): void {
            $this->cascadeDeleteFolder($folder, $businessId);
        });

        $this->activity->record($businessId, $user, 'folder_deleted', 'folder', null, $folderName, $folderParentId, $cabinetId);
    }

    public function exportFolder(int $businessId, User $user, int $folderId): StreamedResponse
    {
        $folder = $this->findFolder($businessId, $folderId);
        $this->access->assertCanView($user, $folder);

        $safeName = $this->safeZipEntryName($folder->name);
        $fileName = $safeName.'.zip';

        return response()->streamDownload(function () use ($businessId, $user, $folder, $safeName): void {
            $zip = new ZipStream(
                operationMode: OperationMode::NORMAL,
                outputName: $safeName.'.zip',
                sendHttpHeaders: false,
            );

            $this->appendFolderToZip($zip, $folder, $businessId, $user, $safeName);
            $zip->finish();
        }, $fileName, [
            'Content-Type' => 'application/zip',
        ]);
    }

    /**
     * @return array{bytes: string, filename: string}
     */
    public function buildFolderZipBytes(int $businessId, User $user, int $folderId): array
    {
        $folder = $this->findFolder($businessId, $folderId);
        $this->access->assertCanView($user, $folder);

        $safeName = $this->safeZipEntryName($folder->name);

        ob_start();
        $zip = new ZipStream(
            operationMode: OperationMode::NORMAL,
            outputName: $safeName.'.zip',
            sendHttpHeaders: false,
        );
        $this->appendFolderToZip($zip, $folder, $businessId, $user, $safeName);
        $zip->finish();
        $bytes = ob_get_clean();

        return [
            'bytes' => $bytes === false ? '' : $bytes,
            'filename' => $safeName.'.zip',
        ];
    }

    protected function appendFolderToZip(
        ZipStream $zip,
        DocumentFolder $folder,
        int $businessId,
        User $user,
        string $basePath,
    ): void {
        if (! $this->access->canView($user, $folder)) {
            return;
        }

        $documents = Document::query()
            ->where('business_id', $businessId)
            ->where('folder_id', $folder->id)
            ->orderBy('title')
            ->get();

        foreach ($documents as $document) {
            if (! $this->access->canView($user, $document)) {
                continue;
            }

            $entryName = $basePath.'/'.$this->safeZipEntryName($document->file_name ?: $document->title);

            if ($document->type === 'link' && $document->url) {
                $zip->addFile($entryName.'.url', "[InternetShortcut]\r\nURL={$document->url}\r\n");

                continue;
            }

            if ($document->file_path && Storage::disk('public')->exists($document->file_path)) {
                $zip->addFileFromPath(
                    $entryName,
                    Storage::disk('public')->path($document->file_path),
                );
            }
        }

        $children = DocumentFolder::query()
            ->where('business_id', $businessId)
            ->where('parent_id', $folder->id)
            ->orderBy('name')
            ->get();

        foreach ($children as $child) {
            $childPath = $basePath.'/'.$this->safeZipEntryName($child->name);
            $this->appendFolderToZip($zip, $child, $businessId, $user, $childPath);
        }
    }

    protected function safeZipEntryName(string $name): string
    {
        $cleaned = trim(str_replace(['\\', '/', "\0"], '-', $name));

        return $cleaned !== '' ? $cleaned : 'untitled';
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
            'cabinet_id' => $folder->cabinet_id,
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
