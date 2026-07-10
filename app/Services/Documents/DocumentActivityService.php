<?php

declare(strict_types=1);

namespace App\Services\Documents;

use App\Models\DocumentActivityLog;
use App\Models\User;
use Illuminate\Support\Carbon;

class DocumentActivityService
{
    /** @param  array<string, mixed>  $metadata */
    public function record(
        int $businessId,
        ?User $actor,
        string $action,
        string $subjectType,
        ?int $subjectId,
        ?string $subjectName,
        ?int $folderId = null,
        array $metadata = [],
    ): void {
        DocumentActivityLog::query()->create([
            'business_id' => $businessId,
            'actor_id' => $actor?->id,
            'action' => $action,
            'subject_type' => $subjectType,
            'subject_id' => $subjectId,
            'subject_name' => $subjectName,
            'folder_id' => $folderId,
            'metadata' => $metadata === [] ? null : $metadata,
            'created_at' => Carbon::now(),
        ]);
    }

    /** @return array{data: list<array<string, mixed>>, meta: array<string, int>} */
    public function listRecent(int $businessId, int $page = 1, int $perPage = 30): array
    {
        $perPage = min(max($perPage, 1), 100);
        $page = max($page, 1);

        $paginator = DocumentActivityLog::query()
            ->where('business_id', $businessId)
            ->with('actor:id,name,avatar')
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate($perPage, ['*'], 'page', $page);

        $data = $paginator->getCollection()
            ->map(fn (DocumentActivityLog $entry) => $this->serialize($entry))
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

    /** @return array<string, mixed> */
    public function serialize(DocumentActivityLog $entry): array
    {
        $actorName = $entry->actor?->name ?? 'Someone';

        return [
            'id' => $entry->id,
            'action' => $entry->action,
            'message' => $this->messageFor($entry->action, $actorName, $entry->subject_name, $entry->metadata ?? []),
            'subject_type' => $entry->subject_type,
            'subject_id' => $entry->subject_id,
            'subject_name' => $entry->subject_name,
            'folder_id' => $entry->folder_id,
            'metadata' => $entry->metadata,
            'created_at' => $entry->created_at?->toISOString(),
            'actor' => $entry->actor ? [
                'id' => $entry->actor->id,
                'name' => $entry->actor->name,
                'avatar' => $entry->actor->avatar,
            ] : null,
        ];
    }

    /** @param  array<string, mixed>  $metadata */
    protected function messageFor(string $action, string $actorName, ?string $subjectName, array $metadata): string
    {
        $name = $subjectName ?: 'item';

        return match ($action) {
            'folder_created' => "{$actorName} created folder {$name}",
            'folder_renamed' => "{$actorName} renamed folder to {$name}",
            'folder_moved' => "{$actorName} moved folder {$name}",
            'folder_deleted' => "{$actorName} deleted folder {$name}",
            'folder_access_changed' => "{$actorName} updated access for folder {$name}",
            'folder_color_changed' => "{$actorName} changed color for folder {$name}",
            'document_uploaded' => "{$actorName} uploaded {$name}",
            'document_linked' => "{$actorName} added link {$name}",
            'document_renamed' => "{$actorName} renamed file to {$name}",
            'document_moved' => "{$actorName} moved {$name}",
            'document_deleted' => "{$actorName} deleted {$name}",
            'document_access_changed' => "{$actorName} updated access for {$name}",
            default => "{$actorName} updated {$name}",
        };
    }
}
