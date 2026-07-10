<?php

declare(strict_types=1);

namespace App\Services\Documents;

use App\Models\Customer;
use App\Models\Document;
use App\Models\DocumentFolder;
use App\Models\DocumentTag;
use App\Models\Project;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class DocumentService
{
    public function __construct(
        protected DocumentAccessService $access,
        protected DocumentTagService $tags,
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
        int $page = 1,
        int $perPage = 50,
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
                'folder:id,name,parent_id,visibility',
            ])
            ->orderByDesc('updated_at');

        if ($folderId !== null) {
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
    ): array {
        $this->access->assertHasDocumentsModule($user);
        $this->assertFolderUploadAccess($businessId, $user, $folderId);
        $this->access->assertValidVisibility($visibility, $memberUserIds, true);
        $this->assertLinkedEntities($businessId, $customerId, $projectId);

        $maxKb = (int) config('documents.max_file_size_kb', 102400);
        if ($file->getSize() > $maxKb * 1024) {
            abort(422, 'File exceeds maximum size of '.round($maxKb / 1024, 1).' MB.');
        }

        $mime = $file->getMimeType() ?: 'application/octet-stream';
        $type = Str::startsWith($mime, 'image/') ? 'image' : 'file';
        $storedPath = $file->store($this->storagePath($businessId), 'public');

        $document = Document::create([
            'business_id' => $businessId,
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
    ): array {
        $this->access->assertHasDocumentsModule($user);
        $this->assertFolderUploadAccess($businessId, $user, $folderId);
        $this->access->assertValidVisibility($visibility, $memberUserIds, true);
        $this->assertLinkedEntities($businessId, $customerId, $projectId);

        $document = Document::create([
            'business_id' => $businessId,
            'folder_id' => $folderId,
            'type' => 'link',
            'title' => trim($title),
            'description' => $description,
            'visibility' => $visibility,
            'customer_id' => $customerId,
            'project_id' => $projectId,
            'url' => $this->normalizeUrl($url),
            'uploaded_by' => $user->id,
        ]);

        $this->access->syncDocumentMembers($document, $businessId, $memberUserIds, $memberRoles);
        $this->tags->syncDocumentTags($document, $businessId, $tagNames);

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

        if ($title !== null) {
            $document->title = trim($title);
        }
        if ($description !== null) {
            $document->description = $description;
        }
        if ($url !== null && $document->type === 'link') {
            $document->url = $this->normalizeUrl($url);
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
            $this->assertFolderUploadAccess($businessId, $user, $folderId);
            $document->folder_id = $folderId;
        }

        if ($unsetCustomer) {
            $document->customer_id = null;
        } elseif ($customerId !== null) {
            $this->assertLinkedEntities($businessId, $customerId, null);
            $document->customer_id = $customerId;
        }

        if ($unsetProject) {
            $document->project_id = null;
        } elseif ($projectId !== null) {
            $this->assertLinkedEntities($businessId, null, $projectId);
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

        return $this->serializeDocument($this->reloadDocument($document), $user);
    }

    public function destroy(int $businessId, User $user, int $documentId): void
    {
        $document = $this->findDocument($businessId, $documentId);

        if (! $this->access->canDeleteDocument($user, $document)) {
            abort(403, 'You cannot delete this document.');
        }

        $this->deleteFileFromDisk($document);
        $document->memberLinks()->delete();
        $document->tags()->detach();
        $document->delete();
    }

    /** @return array{file_url: string|null} */
    public function recordView(int $businessId, User $user, int $documentId): array
    {
        $document = $this->findDocument($businessId, $documentId);
        $this->access->assertCanView($user, $document);
        $document->increment('views_count');

        return ['file_url' => $this->fileUrl($document)];
    }

    /** @return array{file_url: string|null} */
    public function recordDownload(int $businessId, User $user, int $documentId): array
    {
        $document = $this->findDocument($businessId, $documentId);
        $this->access->assertCanView($user, $document);
        $document->increment('downloads_count');

        return ['file_url' => $this->fileUrl($document)];
    }

    public function deleteFileFromDisk(Document $document): void
    {
        if ($document->file_path) {
            Storage::disk('public')->delete($document->file_path);
        }
    }

    protected function assertFolderUploadAccess(int $businessId, User $user, ?int $folderId): void
    {
        if ($folderId === null) {
            $this->access->assertHasDocumentsModule($user);

            return;
        }

        $folder = DocumentFolder::query()
            ->where('business_id', $businessId)
            ->whereKey($folderId)
            ->firstOrFail();

        $this->access->assertCanContributeToFolder($user, $folder);
    }

    protected function assertLinkedEntities(int $businessId, ?int $customerId, ?int $projectId): void
    {
        if ($customerId !== null) {
            Customer::query()->where('business_id', $businessId)->whereKey($customerId)->firstOrFail();
        }

        if ($projectId !== null) {
            Project::query()->where('business_id', $businessId)->whereKey($projectId)->firstOrFail();
        }
    }

    protected function storagePath(int $businessId): string
    {
        return trim(config('documents.storage_folder', 'business-documents'), '/').'/'.$businessId;
    }

    protected function normalizeUrl(string $url): string
    {
        $trimmed = trim($url);
        if ($trimmed === '') {
            abort(422, 'URL is required for link documents.');
        }

        if (! Str::startsWith($trimmed, ['http://', 'https://'])) {
            $trimmed = 'https://'.$trimmed;
        }

        if (! filter_var($trimmed, FILTER_VALIDATE_URL)) {
            abort(422, 'Enter a valid URL.');
        }

        return $trimmed;
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
        return $document->fresh([
            'uploader:id,name,avatar',
            'members:id,name,avatar',
            'tags:id,name,slug',
            'customer:id,name',
            'project:id,name',
            'folder:id,name,parent_id,visibility',
        ]) ?? $document;
    }

    protected function fileUrl(Document $document): ?string
    {
        if ($document->file_path) {
            return url('storage/'.ltrim($document->file_path, '/'));
        }

        return $document->url;
    }

    /** @return array<string, mixed> */
    public function serializeDocument(Document $document, User $user): array
    {
        $flags = $this->access->permissionFlags($user, $document);

        return [
            'id' => $document->id,
            'folder_id' => $document->folder_id,
            'type' => $document->type,
            'title' => $document->title,
            'description' => $document->description,
            'visibility' => $document->visibility,
            'url' => $document->url,
            'file_name' => $document->file_name,
            'file_path' => $document->file_path,
            'file_url' => $this->fileUrl($document),
            'mime_type' => $document->mime_type,
            'file_size' => $document->file_size,
            'views_count' => $document->views_count,
            'downloads_count' => $document->downloads_count,
            'customer_id' => $document->customer_id,
            'project_id' => $document->project_id,
            'customer' => $document->customer ? [
                'id' => $document->customer->id,
                'name' => $document->customer->name,
            ] : null,
            'project' => $document->project ? [
                'id' => $document->project->id,
                'name' => $document->project->name,
            ] : null,
            'tags' => $document->tags->map(fn (DocumentTag $tag) => [
                'id' => $tag->id,
                'name' => $tag->name,
                'slug' => $tag->slug,
            ])->values()->all(),
            'created_at' => $document->created_at?->toISOString(),
            'updated_at' => $document->updated_at?->toISOString(),
            'uploader' => $document->uploader ? [
                'id' => $document->uploader->id,
                'name' => $document->uploader->name,
                'avatar' => $document->uploader->avatar,
            ] : null,
            'members' => $document->members->map(fn ($member) => [
                'id' => $member->id,
                'name' => $member->name,
                'avatar' => $member->avatar,
                'role' => $member->pivot->role ?? 'viewer',
            ])->values()->all(),
            ...$flags,
        ];
    }
}
