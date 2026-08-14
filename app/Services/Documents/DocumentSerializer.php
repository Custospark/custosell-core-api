<?php

declare(strict_types=1);

namespace App\Services\Documents;

use App\Models\Document;
use App\Models\DocumentFolder;
use App\Models\DocumentTag;
use App\Models\User;

class DocumentSerializer
{
    public function __construct(
        protected DocumentAccessService $access,
        protected DocumentTagService $tags,
    ) {}

    /** @return array<string, mixed> */
    public function serialize(Document $document, User $user): array
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

    public function reload(Document $document): Document
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

    public function folderPathForDocument(Document $document): ?string
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

    public function fileUrl(Document $document): ?string
    {
        if ($document->file_path) {
            return url('storage/'.ltrim($document->file_path, '/'));
        }

        return $document->url;
    }
}
