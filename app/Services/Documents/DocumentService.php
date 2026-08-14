<?php

declare(strict_types=1);

namespace App\Services\Documents;

use App\Models\Customer;
use App\Models\Document;
use App\Models\DocumentCabinet;
use App\Models\DocumentFolder;
use App\Models\Project;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;

class DocumentService
{
    public function __construct(
        protected DocumentAccessService $access,
        protected DocumentTagService $tags,
        protected DocumentActivityService $activity,
        protected DocumentFileStorage $fileStorage,
        protected DocumentFolderResolver $folderResolver,
        protected DocumentSerializer $serializer,
    ) {}

    /** @return array{data: list<array<string, mixed>>, meta: array<string, int>} */
    public function listPaginated(
        int $businessId,
        User $user,
        ?string $query = null,
        ?int $folderId = null,
        ?string $tag = null,
        ?int $customerId = null,
        ?int $projectId = null,
        ?string $type = null,
        ?int $uploadedBy = null,
        bool $rootOnly = false,
        int $page = 1,
        int $perPage = 50,
        ?int $cabinetId = null,
    ): array {
        $perPage = min(max($perPage, 1), 200);
        $page = max($page, 1);

        $builder = Document::query()
            ->where('business_id', $businessId)
            ->with([
                'uploader:id,name,avatar',
                'members:id,name,avatar',
                'tags:id,name,slug',
                'customer:id,name',
                'project:id,name',
                'folder:id,name,parent_id,visibility,cabinet_id',
            ])
            ->orderByDesc('updated_at');

        if ($cabinetId !== null) {
            $builder->where('cabinet_id', $cabinetId);
        }

        if ($rootOnly) {
            $builder->whereNull('folder_id');
        } elseif ($folderId !== null) {
            $builder->where('folder_id', $folderId);
        }

        if ($customerId !== null) {
            $builder->where('customer_id', $customerId);
        }

        if ($projectId !== null) {
            $builder->where('project_id', $projectId);
        }

        if ($type !== null && $type !== '') {
            $builder->where('type', $type);
        }

        if ($uploadedBy !== null) {
            $builder->where('uploaded_by', $uploadedBy);
        }

        if ($tag !== null && $tag !== '') {
            $builder->whereHas('tags', fn ($q) => $q->where('slug', Str::slug($tag)));
        }

        if ($query !== null && trim($query) !== '') {
            $term = '%'.trim($query).'%';
            $builder->where(function ($q) use ($term): void {
                $q->where('title', 'like', $term)
                    ->orWhere('description', 'like', $term)
                    ->orWhere('file_name', 'like', $term)
                    ->orWhereHas('tags', fn ($tagQuery) => $tagQuery->where('name', 'like', $term));
            });
        }

        $paginator = $builder->paginate($perPage, ['*'], 'page', $page);

        $data = $paginator->getCollection()
            ->filter(fn (Document $item) => $this->access->canView($user, $item))
            ->map(fn (Document $item) => $this->serializeDocument($item, $user))
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

    /** @return list<array<string, mixed>> */
    public function list(
        int $businessId,
        User $user,
        ?string $query = null,
        ?int $folderId = null,
        ?string $tag = null,
        ?int $customerId = null,
        ?int $projectId = null,
        ?string $type = null,
        ?int $uploadedBy = null,
        bool $rootOnly = false,
        int $page = 1,
        int $perPage = 50,
    ): array {
        return $this->listPaginated(
            $businessId,
            $user,
            $query,
            $folderId,
            $tag,
            $customerId,
            $projectId,
            $type,
            $uploadedBy,
            $rootOnly,
            $page,
            $perPage,
        )['data'];
    }

    /** @return array<string, mixed> */
    public function show(int $businessId, User $user, int $documentId): array
    {
        $document = $this->findDocument($businessId, $documentId);
        $this->access->assertCanView($user, $document);

        return $this->serializeDocument($this->reloadDocument($document), $user);
    }

    /** @param  list<int>  $memberUserIds
     * @param  array<int, string>  $memberRoles
     * @param  list<string>  $tagNames
     * @return array<string, mixed>
     */
    public function upload(
        int $businessId,
        User $user,
        UploadedFile $file,
        ?int $folderId,
        ?string $title,
        string $visibility,
        ?string $description,
        array $memberUserIds = [],
        array $memberRoles = [],
        ?int $customerId = null,
        ?int $projectId = null,
        array $tagNames = [],
        ?int $cabinetId = null,
    ): array {
        $this->access->assertHasDocumentsModule($user);
        $resolvedCabinetId = $this->folderResolver->resolveCabinetIdForWrite($businessId, $user, $folderId, $cabinetId);
        $this->folderResolver->assertFolderUploadAccess($businessId, $user, $folderId, $resolvedCabinetId);
        $this->access->assertValidVisibility($visibility, $memberUserIds, true);
        $this->folderResolver->assertLinkedEntities($businessId, $customerId, $projectId);

        $maxKb = (int) config('documents.max_file_size_kb', 102400);
        $mime = $file->getMimeType() ?: 'application/octet-stream';
        if ($this->fileStorage->isMediaMime($mime, $file->getClientOriginalName())) {
            $maxKb = (int) config('documents.max_media_file_size_kb', 10240);
        }
        if ($file->getSize() > $maxKb * 1024) {
            $label = $this->fileStorage->isMediaMime($mime, $file->getClientOriginalName()) ? 'audio/video' : 'file';
            abort(422, ucfirst($label).' exceeds maximum size of '.round($maxKb / 1024, 1).' MB.');
        }

        $type = Str::startsWith($mime, 'image/') ? 'image' : 'file';
        $storedPath = $file->store($this->fileStorage->storagePath($businessId), 'public');

        $document = Document::create([
            'business_id' => $businessId,
            'cabinet_id' => $resolvedCabinetId,
            'folder_id' => $folderId,
            'type' => $type,
            'title' => $title ? trim($title) : ($file->getClientOriginalName() ?: 'Untitled'),
            'description' => $description,
            'visibility' => $visibility,
            'customer_id' => $customerId,
            'project_id' => $projectId,
            'file_name' => $file->getClientOriginalName(),
            'file_path' => $storedPath,
            'mime_type' => $mime,
            'file_size' => $file->getSize(),
            'uploaded_by' => $user->id,
        ]);

        $this->access->syncDocumentMembers($document, $businessId, $memberUserIds, $memberRoles);
        $this->tags->syncDocumentTags($document, $businessId, $tagNames);

        $this->activity->record(
            $businessId,
            $user,
            'document_uploaded',
            'document',
            $document->id,
            $document->title,
            $document->folder_id,
            $document->cabinet_id,
        );

        return $this->serializeDocument($this->reloadDocument($document), $user);
    }

    /** @param  list<int>  $memberUserIds
     * @param  array<int, string>  $memberRoles
     * @param  list<string>  $tagNames
     * @return array<string, mixed>
     */
    public function createLink(
        int $businessId,
        User $user,
        string $title,
        string $url,
        ?int $folderId,
        string $visibility,
        ?string $description,
        array $memberUserIds = [],
        array $memberRoles = [],
        ?int $customerId = null,
        ?int $projectId = null,
        array $tagNames = [],
        ?int $cabinetId = null,
    ): array {
        $this->access->assertHasDocumentsModule($user);
        $resolvedCabinetId = $this->folderResolver->resolveCabinetIdForWrite($businessId, $user, $folderId, $cabinetId);
        $this->folderResolver->assertFolderUploadAccess($businessId, $user, $folderId, $resolvedCabinetId);
        $this->access->assertValidVisibility($visibility, $memberUserIds, true);
        $this->folderResolver->assertLinkedEntities($businessId, $customerId, $projectId);

        $document = Document::create([
            'business_id' => $businessId,
            'cabinet_id' => $resolvedCabinetId,
            'folder_id' => $folderId,
            'type' => 'link',
            'title' => trim($title),
            'description' => $description,
            'visibility' => $visibility,
            'customer_id' => $customerId,
            'project_id' => $projectId,
            'url' => $this->folderResolver->normalizeUrl($url),
            'uploaded_by' => $user->id,
        ]);

        $this->access->syncDocumentMembers($document, $businessId, $memberUserIds, $memberRoles);
        $this->tags->syncDocumentTags($document, $businessId, $tagNames);

        $this->activity->record(
            $businessId,
            $user,
            'document_linked',
            'document',
            $document->id,
            $document->title,
            $document->folder_id,
            $document->cabinet_id,
        );

        return $this->serializeDocument($this->reloadDocument($document), $user);
    }

    /** @param  list<int>|null  $memberUserIds
     * @param  array<int, string>|null  $memberRoles
     * @param  list<string>|null  $tagNames
     * @return array<string, mixed>
     */
    public function update(
        int $businessId,
        User $user,
        int $documentId,
        ?string $title = null,
        ?string $description = null,
        ?string $visibility = null,
        ?int $folderId = null,
        ?array $memberUserIds = null,
        ?array $memberRoles = null,
        ?int $customerId = null,
        ?int $projectId = null,
        ?array $tagNames = null,
        ?string $url = null,
        bool $unsetCustomer = false,
        bool $unsetProject = false,
    ): array {
        $document = $this->findDocument($businessId, $documentId);
        $this->access->assertCanView($user, $document);

        if (! $this->access->canEditDocument($user, $document)) {
            abort(403, 'You cannot edit this document.');
        }

        $previousTitle = $document->title;
        $previousFolderId = $document->folder_id;
        $previousVisibility = $document->visibility;
        $hadMemberUpdate = $memberUserIds !== null;

        if ($title !== null) {
            $document->title = trim($title);
        }
        if ($description !== null) {
            $document->description = $description;
        }
        if ($url !== null && $document->type === 'link') {
            $document->url = $this->folderResolver->normalizeUrl($url);
        }

        if ($visibility !== null) {
            $this->access->assertValidVisibility(
                $visibility,
                $memberUserIds ?? $document->members()->pluck('users.id')->map(fn ($id) => (int) $id)->all(),
                true,
            );
            $document->visibility = $visibility;
        }

        if ($folderId !== null) {
            $targetFolder = DocumentFolder::query()
                ->where('business_id', $businessId)
                ->whereKey($folderId)
                ->firstOrFail();
            $this->folderResolver->assertFolderUploadAccess($businessId, $user, $folderId, (int) $targetFolder->cabinet_id);
            $document->folder_id = $folderId;
            $document->cabinet_id = $targetFolder->cabinet_id;
        }

        if ($unsetCustomer) {
            $document->customer_id = null;
        } elseif ($customerId !== null) {
            $this->folderResolver->assertLinkedEntities($businessId, $customerId, null);
            $document->customer_id = $customerId;
        }

        if ($unsetProject) {
            $document->project_id = null;
        } elseif ($projectId !== null) {
            $this->folderResolver->assertLinkedEntities($businessId, null, $projectId);
            $document->project_id = $projectId;
        }

        $document->save();

        if ($memberUserIds !== null) {
            $this->access->syncDocumentMembers($document, $businessId, $memberUserIds, $memberRoles ?? []);
        } elseif ($visibility !== null && $visibility !== 'selected_staff') {
            $document->memberLinks()->delete();
        }

        if ($tagNames !== null) {
            $this->tags->syncDocumentTags($document, $businessId, $tagNames);
        }

        $reloaded = $this->reloadDocument($document);

        if ($title !== null && trim($title) !== $previousTitle) {
            $this->activity->record($businessId, $user, 'document_renamed', 'document', $document->id, $document->title, $document->folder_id, $document->cabinet_id);
        }
        if ($folderId !== null && $folderId !== $previousFolderId) {
            $this->activity->record($businessId, $user, 'document_moved', 'document', $document->id, $document->title, $document->folder_id, $document->cabinet_id);
        }
        if (($visibility !== null && $visibility !== $previousVisibility) || $hadMemberUpdate) {
            $this->activity->record($businessId, $user, 'document_access_changed', 'document', $document->id, $document->title, $document->folder_id, $document->cabinet_id);
        }

        return $this->serializeDocument($reloaded, $user);
    }

    public function destroy(int $businessId, User $user, int $documentId): void
    {
        $document = $this->findDocument($businessId, $documentId);

        if (! $this->access->canDeleteDocument($user, $document)) {
            abort(403, 'You cannot delete this document.');
        }

        $title = $document->title;
        $folderId = $document->folder_id;
        $cabinetId = $document->cabinet_id;

        $this->fileStorage->deleteFileFromDisk($document);
        $document->memberLinks()->delete();
        $document->tags()->detach();
        $document->delete();

        $this->activity->record($businessId, $user, 'document_deleted', 'document', null, $title, $folderId, $cabinetId);
    }

    /** @return array{file_url: string|null} */
    public function recordView(int $businessId, User $user, int $documentId): array
    {
        $document = $this->findDocument($businessId, $documentId);
        $this->access->assertCanView($user, $document);
        $document->increment('views_count');

        return ['file_url' => $this->serializer->fileUrl($document)];
    }

    /** @return array{file_url: string|null} */
    public function recordDownload(int $businessId, User $user, int $documentId): array
    {
        $document = $this->findDocument($businessId, $documentId);
        $this->access->assertCanView($user, $document);
        $document->increment('downloads_count');

        return ['file_url' => $this->serializer->fileUrl($document)];
    }

    /** @return array{content: string, content_type: string, encoding: string, editable: bool, truncated: bool} */
    public function getFileContent(int $businessId, User $user, int $documentId): array
    {
        $document = $this->findDocument($businessId, $documentId);

        return $this->fileStorage->getFileContent($businessId, $user, $document);
    }

    /** @return array<string, mixed> */
    public function updateFileContent(int $businessId, User $user, int $documentId, string $content): array
    {
        $document = $this->findDocument($businessId, $documentId);
        $this->fileStorage->updateFileContent($businessId, $user, $document, $content);

        return $this->serializeDocument($this->reloadDocument($document), $user);
    }

    protected function findDocument(int $businessId, int $documentId): Document
    {
        return Document::query()
            ->where('business_id', $businessId)
            ->whereKey($documentId)
            ->firstOrFail();
    }

    protected function reloadDocument(Document $document): Document
    {
        return $this->serializer->reload($document);
    }

    /** @return array<string, mixed> */
    public function serializeDocument(Document $document, User $user): array
    {
        return $this->serializer->serialize($document, $user);
    }
}
