<?php

declare(strict_types=1);

namespace App\Services\Pipeline;

use App\Models\PipelineBoard;
use App\Models\PipelineBoardActivityEvent;
use App\Models\User;
use App\Services\PipelineService;
use Illuminate\Support\Collection;

class PipelineBoardActivityService
{
    public function __construct(
        protected PipelineService $pipeline,
    ) {}

    public function log(
        PipelineBoard $board,
        ?User $actor,
        string $eventType,
        string $title,
        ?string $body = null,
        ?string $entityType = null,
        ?int $entityId = null,
        ?array $metadata = null,
    ): PipelineBoardActivityEvent {
        return PipelineBoardActivityEvent::create([
            'business_id' => $board->business_id,
            'board_id' => $board->id,
            'user_id' => $actor?->id,
            'event_type' => $eventType,
            'title' => $title,
            'body' => $body,
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'metadata' => $metadata,
        ]);
    }

    /** @return list<array<string, mixed>> */
    public function listActivity(int $businessId, User $user, int $boardId, int $limit = 100): array
    {
        $board = $this->pipeline->getBoard($businessId, $user, $boardId);

        return PipelineBoardActivityEvent::query()
            ->where('board_id', $board->id)
            ->with('user:id,name,avatar')
            ->orderByDesc('created_at')
            ->limit($limit)
            ->get()
            ->map(fn (PipelineBoardActivityEvent $event) => [
                'id' => $event->id,
                'board_id' => $event->board_id,
                'event_type' => $event->event_type,
                'title' => $event->title,
                'body' => $event->body,
                'entity_type' => $event->entity_type,
                'entity_id' => $event->entity_id,
                'metadata' => $event->metadata,
                'created_at' => $event->created_at?->toISOString(),
                'user' => $event->user ? [
                    'id' => $event->user->id,
                    'name' => $event->user->name,
                    'avatar' => $event->user->avatar,
                ] : null,
            ])
            ->all();
    }

    /** @return array{activity_count: int} */
    public function activitySummary(int $businessId, User $user, int $boardId): array
    {
        $board = $this->pipeline->getBoard($businessId, $user, $boardId);

        return [
            'activity_count' => PipelineBoardActivityEvent::query()
                ->where('board_id', $board->id)
                ->count(),
        ];
    }
}
