<?php

declare(strict_types=1);

namespace App\Services\Documents;

use App\Models\Customer;
use App\Models\Document;
use App\Models\DocumentCabinet;
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
        protected DocumentActivityService $activity,
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
        $resolvedCabinetId = $this->resolveCabinetIdForWrite($businessId, $user, $folderId, $cabinetId);
        $this->assertFolderUploadAccess($businessId, $user, $folderId, $resolvedCabinetId);
        $this->access->assertValidVisibility($visibility, $memberUserIds, true);
        $this->assertLinkedEntities($businessId, $customerId, $projectId);

        $maxKb = (int) config('documents.max_file_size_kb', 102400);
        $mime = $file->getMimeType() ?: 'application/octet-stream';
        if ($this->isMediaMime($mime, $file->getClientOriginalName())) {
            $maxKb = (int) config('documents.max_media_file_size_kb', 10240);
        }
        if ($file->getSize() > $maxKb * 1024) {
            $label = $this->isMediaMime($mime, $file->getClientOriginalName()) ? 'audio/video' : 'file';
            abort(422, ucfirst($label).' exceeds maximum size of '.round($maxKb / 1024, 1).' MB.');
        }

        $type = Str::startsWith($mime, 'image/') ? 'image' : 'file';
        $storedPath = $file->store($this->storagePath($businessId), 'public');

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
        $resolvedCabinetId = $this->resolveCabinetIdForWrite($businessId, $user, $folderId, $cabinetId);
        $this->assertFolderUploadAccess($businessId, $user, $folderId, $resolvedCabinetId);
        $this->access->assertValidVisibility($visibility, $memberUserIds, true);
        $this->assertLinkedEntities($businessId, $customerId, $projectId);

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
            'url' => $this->normalizeUrl($url),
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
            $targetFolder = DocumentFolder::query()
                ->where('business_id', $businessId)
                ->whereKey($folderId)
                ->firstOrFail();
            $this->assertFolderUploadAccess($businessId, $user, $folderId, (int) $targetFolder->cabinet_id);
            $document->folder_id = $folderId;
            $document->cabinet_id = $targetFolder->cabinet_id;
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

        $this->deleteFileFromDisk($document);
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

    /** @return array{content: string, content_type: string, encoding: string, editable: bool, truncated: bool} */
    public function getFileContent(int $businessId, User $user, int $documentId): array
    {
        $document = $this->findDocument($businessId, $documentId);
        $this->access->assertCanView($user, $document);

        if ($document->type === 'link' || ! $document->file_path) {
            abort(422, 'This document has no file content.');
        }

        $diskPath = Storage::disk('public')->path($document->file_path);
        if (! is_readable($diskPath)) {
            abort(404, 'File not found on disk.');
        }

        $maxBytes = (int) config('documents.max_text_content_size_kb', 2048) * 1024;
        $fileSize = (int) ($document->file_size ?? filesize($diskPath) ?: 0);
        $mime = strtolower((string) ($document->mime_type ?? ''));
        $fileName = (string) ($document->file_name ?? '');

        if ($this->isWordDocument($mime, $fileName)) {
            $content = $this->extractDocxText($diskPath);
            $truncated = strlen($content) > $maxBytes;
            if ($truncated) {
                $content = substr($content, 0, $maxBytes);
            }

            return [
                'content' => $content,
                'content_type' => 'word',
                'encoding' => 'utf-8',
                'editable' => false,
                'truncated' => $truncated,
            ];
        }

        if (! $this->isTextViewableMime($mime, $fileName)) {
            abort(422, 'Inline text viewing is not supported for this file type.');
        }

        if ($fileSize > $maxBytes) {
            abort(422, 'File is too large to view inline. Download the file instead.');
        }

        $raw = file_get_contents($diskPath);
        if ($raw === false) {
            abort(500, 'Could not read file.');
        }

        if (! mb_check_encoding($raw, 'UTF-8')) {
            abort(422, 'This file uses an encoding that cannot be displayed inline. Download the file instead.');
        }

        $contentType = $this->isCsvMime($mime, $fileName) ? 'csv' : 'text';

        return [
            'content' => $raw,
            'content_type' => $contentType,
            'encoding' => 'utf-8',
            'editable' => $this->access->canEditDocument($user, $document),
            'truncated' => false,
        ];
    }

    /** @return array<string, mixed> */
    public function updateFileContent(int $businessId, User $user, int $documentId, string $content): array
    {
        $document = $this->findDocument($businessId, $documentId);

        if (! $this->access->canEditDocument($user, $document)) {
            abort(403, 'You cannot edit this document.');
        }

        if ($document->type === 'link' || ! $document->file_path) {
            abort(422, 'This document has no file content.');
        }

        $mime = strtolower((string) ($document->mime_type ?? ''));
        $fileName = (string) ($document->file_name ?? '');

        if ($this->isWordDocument($mime, $fileName) || ! $this->isTextViewableMime($mime, $fileName)) {
            abort(422, 'This file type cannot be edited inline.');
        }

        $maxBytes = (int) config('documents.max_text_content_size_kb', 2048) * 1024;
        if (strlen($content) > $maxBytes) {
            abort(422, 'Content exceeds the maximum editable size of '.round($maxBytes / (1024 * 1024), 1).' MB.');
        }

        if (! mb_check_encoding($content, 'UTF-8')) {
            abort(422, 'Content must be valid UTF-8 text.');
        }

        $written = Storage::disk('public')->put($document->file_path, $content);
        if (! $written) {
            abort(500, 'Could not save file.');
        }

        $document->update([
            'file_size' => strlen($content),
        ]);

        $this->activity->record(
            $businessId,
            $user,
            'document_updated',
            'document',
            $document->id,
            $document->title,
            $document->folder_id,
            $document->cabinet_id,
        );

        return $this->serializeDocument($this->reloadDocument($document), $user);
    }

    protected function isMediaMime(string $mime, ?string $fileName = null): bool
    {
        $mime = strtolower($mime);
        if (Str::startsWith($mime, ['audio/', 'video/'])) {
            return true;
        }

        $ext = $this->extensionOf($fileName);

        return in_array($ext, ['mp3', 'wav', 'ogg', 'm4a', 'aac', 'flac', 'webm', 'mp4', 'mov', 'm4v', 'ogv'], true);
    }

    protected function isWordDocument(string $mime, ?string $fileName = null): bool
    {
        $ext = $this->extensionOf($fileName);

        return $ext === 'docx' || str_contains($mime, 'wordprocessingml');
    }

    protected function isCsvMime(string $mime, ?string $fileName = null): bool
    {
        $ext = $this->extensionOf($fileName);

        return in_array($ext, ['csv', 'tsv'], true) || str_contains($mime, 'csv') || $mime === 'text/tab-separated-values';
    }

    protected function isTextViewableMime(string $mime, ?string $fileName = null): bool
    {
        if ($this->isWordDocument($mime, $fileName) || $this->isCsvMime($mime, $fileName)) {
            return true;
        }

        if (Str::startsWith($mime, 'text/')) {
            return true;
        }

        if (in_array($mime, ['application/json', 'application/xml'], true)) {
            return true;
        }

        $ext = $this->extensionOf($fileName);
        $textExtensions = [
            'txt', 'md', 'markdown', 'json', 'js', 'ts', 'tsx', 'jsx', 'css', 'scss', 'html', 'htm',
            'xml', 'yaml', 'yml', 'py', 'php', 'java', 'cs', 'c', 'cpp', 'h', 'go', 'rs', 'rb', 'sh',
            'sql', 'env', 'ini', 'log', 'vue', 'svelte',
        ];

        return in_array($ext, $textExtensions, true);
    }

    protected function extensionOf(?string $fileName): string
    {
        if (! $fileName) {
            return '';
        }

        $idx = strrpos($fileName, '.');
        if ($idx === false) {
            return '';
        }

        return strtolower(substr($fileName, $idx + 1));
    }

    protected function extractDocxText(string $diskPath): string
    {
        $zip = new \ZipArchive();
        if ($zip->open($diskPath) !== true) {
            abort(422, 'Could not read Word document.');
        }

        $xml = $zip->getFromName('word/document.xml');
        $zip->close();

        if ($xml === false || $xml === '') {
            return '';
        }

        $xml = preg_replace('/<w:tab[^>]*\/>/', "\t", $xml) ?? $xml;
        $xml = preg_replace('/<w:br[^>]*\/>/', "\n", $xml) ?? $xml;
        $xml = preg_replace('/<\/w:p>/', "\n", $xml) ?? $xml;
        $text = strip_tags($xml);

        return trim(html_entity_decode($text, ENT_QUOTES | ENT_XML1, 'UTF-8'));
    }

    protected function folderPathForDocument(Document $document): ?string
    {
        if ($document->folder_id === null) {
            return null;
        }

        $segments = [];
        $folderId = $document->folder_id;
        $visited = [];

        while ($folderId !== null && ! in_array($folderId, $visited, true)) {
            $visited[] = $folderId;
            $folder = DocumentFolder::query()
                ->where('business_id', $document->business_id)
                ->whereKey($folderId)
                ->first();

            if ($folder === null) {
                break;
            }

            array_unshift($segments, $folder->name);
            $folderId = $folder->parent_id;
        }

        return $segments === [] ? null : implode('/', $segments);
    }

    public function deleteFileFromDisk(Document $document): void
    {
        if ($document->file_path) {
            Storage::disk('public')->delete($document->file_path);
        }
    }

    protected function assertFolderUploadAccess(int $businessId, User $user, ?int $folderId, ?int $cabinetId = null): void
    {
        if ($folderId === null) {
            if ($cabinetId === null) {
                abort(422, 'Cabinet is required for root uploads.');
            }
            $cabinet = DocumentCabinet::query()
                ->where('business_id', $businessId)
                ->whereKey($cabinetId)
                ->firstOrFail();
            $this->access->assertCanContributeToCabinet($user, $cabinet);

            return;
        }

        $folder = DocumentFolder::query()
            ->where('business_id', $businessId)
            ->whereKey($folderId)
            ->firstOrFail();

        $this->access->assertCanContributeToFolder($user, $folder);
    }

    protected function resolveCabinetIdForWrite(int $businessId, User $user, ?int $folderId, ?int $cabinetId): int
    {
        if ($folderId !== null) {
            $folder = DocumentFolder::query()
                ->where('business_id', $businessId)
                ->whereKey($folderId)
                ->firstOrFail();

            return (int) $folder->cabinet_id;
        }

        if ($cabinetId === null) {
            abort(422, 'Cabinet is required.');
        }

        $cabinet = DocumentCabinet::query()
            ->where('business_id', $businessId)
            ->whereKey($cabinetId)
            ->firstOrFail();
        $this->access->assertCanContributeToCabinet($user, $cabinet);

        return $cabinetId;
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
            'cabinet_id' => $document->cabinet_id,
            'folder_id' => $document->folder_id,
            'folder_path' => $this->folderPathForDocument($document),
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
            'email_sent_count' => (int) ($document->email_sent_count ?? 0),
            'last_emailed_at' => $document->last_emailed_at?->toISOString(),
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
            'tags' => $document->tags->map(fn (DocumentTag $tag) => $this->tags->serializeTag($tag))->values()->all(),
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
