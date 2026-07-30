<?php

declare(strict_types=1);

namespace App\Services\Pipeline;

use App\Models\PipelineLead;
use App\Models\PipelineStage;
use App\Models\User;
use App\Services\Pipeline\PipelineBoardLookupService;
use App\Services\Pipeline\PipelineBoardPermissionService;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Validation\ValidationException;

class PipelineStageService
{
    public function __construct(
        protected PipelineBoardLookupService $lookup,
        protected PipelineBoardPermissionService $permission,
    ) {}

    public function createStage(int $businessId, User $user, int $boardId, array $data): PipelineStage
    {
        $board = $this->lookup->findBoardForUser($user, $boardId);
        $this->permission->assertCanManageBoard($user, $board);

        $effectiveBusinessId = (int) $board->business_id;
        $maxOrder = PipelineStage::query()->where('board_id', $boardId)->max('sort_order');

        return PipelineStage::create([
            'business_id' => $effectiveBusinessId,
            'board_id' => $boardId,
            'name' => $data['name'],
            'sort_order' => (int) ($data['sort_order'] ?? ($maxOrder + 1)),
            'color' => $data['color'] ?? '#64748b',
            'is_won' => (bool) ($data['is_won'] ?? false),
            'is_lost' => (bool) ($data['is_lost'] ?? false),
            'rotting_days' => $data['rotting_days'] ?? null,
        ]);
    }

    public function updateStage(int $businessId, User $user, int $stageId, array $data): PipelineStage
    {
        $stage = $this->lookup->findStageForUser($user, $stageId);
        $this->permission->assertCanManageBoard($user, $stage->board);

        $stage->update(array_filter([
            'name' => $data['name'] ?? null,
            'color' => $data['color'] ?? null,
            'is_won' => array_key_exists('is_won', $data) ? (bool) $data['is_won'] : null,
            'is_lost' => array_key_exists('is_lost', $data) ? (bool) $data['is_lost'] : null,
            'rotting_days' => array_key_exists('rotting_days', $data) ? $data['rotting_days'] : null,
            'sort_order' => $data['sort_order'] ?? null,
        ], fn ($v) => $v !== null));

        return $stage->fresh();
    }

    public function deleteStage(int $businessId, User $user, int $stageId, ?int $migrateToStageId = null): void
    {
        $stage = $this->lookup->findStageForUser($user, $stageId);
        $this->permission->assertCanManageBoard($user, $stage->board);

        $effectiveBusinessId = (int) $stage->business_id;
        $stageCount = PipelineStage::query()->where('board_id', $stage->board_id)->count();
        if ($stageCount <= 1) {
            throw ValidationException::withMessages(['stage' => 'A board must have at least one stage.']);
        }

        $leadCount = PipelineLead::query()->where('stage_id', $stageId)->whereNull('deleted_at')->count();
        if ($leadCount > 0) {
            if (!$migrateToStageId) {
                throw ValidationException::withMessages(['migrate_to_stage_id' => 'Move leads to another stage before deleting.']);
            }
            $target = PipelineStage::query()
                ->where('board_id', $stage->board_id)
                ->where('business_id', $effectiveBusinessId)
                ->where('id', $migrateToStageId)
                ->where('id', '!=', $stageId)
                ->firstOrFail();

            PipelineLead::query()
                ->where('stage_id', $stageId)
                ->update(['stage_id' => $target->id]);
        }

        $stage->delete();
    }

    public function reorderStages(int $businessId, User $user, int $boardId, array $stageIdsInOrder): Collection
    {
        $board = $this->lookup->findBoardForUser($user, $boardId);
        $this->permission->assertCanEditBoard($user, $board);

        $effectiveBusinessId = (int) $board->business_id;
        foreach ($stageIdsInOrder as $order => $stageId) {
            PipelineStage::query()
                ->where('board_id', $boardId)
                ->where('business_id', $effectiveBusinessId)
                ->where('id', $stageId)
                ->update(['sort_order' => $order]);
        }

        return $board->stages()->orderBy('sort_order')->get();
    }
}
