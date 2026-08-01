<?php

declare(strict_types=1);

namespace App\Services\Pipeline;

use App\Models\PipelineBoard;
use App\Models\PipelineBoardTarget;
use App\Models\User;
use App\Services\PipelineService;
use Carbon\Carbon;
use Illuminate\Validation\ValidationException;

class PipelineBoardTargetService
{
    public function __construct(
        protected PipelineService $pipeline,
        protected PipelineColumnMetricsService $columnMetrics,
        protected PipelineGoalDecompositionService $decomposition,
        protected PipelineBoardProgressPeriodService $period,
        protected PipelineBoardTargetProgressService $targetProgress,
    ) {}

    /** @return list<array<string, mixed>> */
    public function listTargets(int $businessId, User $user, int $boardId): array
    {
        $board = $this->pipeline->getBoard($businessId, $user, $boardId);

        return PipelineBoardTarget::query()
            ->where('board_id', $board->id)
            ->where('status', '!=', 'archived')
            ->with(['member:id,name,avatar', 'keyResults.member:id,name,avatar', 'allocations'])
            ->orderByDesc('created_at')
            ->get()
            ->filter(fn (PipelineBoardTarget $t) => $t->type !== 'key_result')
            ->map(fn (PipelineBoardTarget $t) => $this->targetProgress->serializeTargetTree($t, $board))
            ->values()
            ->all();
    }

    /** @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function storeTarget(int $businessId, User $user, int $boardId, array $data): array
    {
        $board = $this->pipeline->getBoard($businessId, $user, $boardId);
        $this->pipeline->ensureCanManageBoard($user, $board);

        $validated = $this->validateTargetPayload($data, $board);
        $target = PipelineBoardTarget::create([
            ...$validated,
            'business_id' => $businessId,
            'board_id' => $board->id,
            'created_by' => $user->id,
        ]);

        if (! empty($data['allocations']) && is_array($data['allocations'])) {
            $this->decomposition->persistAllocations($businessId, $target, $data['allocations'], $user);
        } elseif (! empty($data['planning_level'])) {
            $preview = $this->decomposition->preview($businessId, $board, [
                'planning_level' => $data['planning_level'],
                'target_value' => $validated['target_value'],
                'anchor_start' => $validated['anchor_start'] ?? $validated['period_start'],
                'anchor_end' => $validated['anchor_end'] ?? $validated['period_end'],
                'stage_ids' => $validated['stage_id'] ? [$validated['stage_id']] : ($data['stage_ids'] ?? []),
                'member_user_ids' => $validated['member_user_id'] ? [(int) $validated['member_user_id']] : [],
                'decomposition_mode' => $validated['decomposition_mode'] ?? 'hybrid',
            ]);
            $this->decomposition->persistAllocations($businessId, $target, $preview['nodes'], $user);
        }

        if (! empty($data['key_results']) && is_array($data['key_results'])) {
            foreach ($data['key_results'] as $kr) {
                $krPayload = $this->validateTargetPayload([
                    ...$kr,
                    'type' => 'key_result',
                    'parent_id' => $target->id,
                    'period_type' => $validated['period_type'],
                    'period_start' => $validated['period_start'],
                    'period_end' => $validated['period_end'],
                    'stage_id' => $kr['stage_id'] ?? $validated['stage_id'],
                ], $board);
                PipelineBoardTarget::create([
                    ...$krPayload,
                    'business_id' => $businessId,
                    'board_id' => $board->id,
                    'parent_id' => $target->id,
                    'type' => 'key_result',
                    'created_by' => $user->id,
                ]);
            }
        }

        $target->load(['member:id,name,avatar', 'keyResults.member:id,name,avatar', 'allocations']);

        return $this->targetProgress->serializeTargetTree($target->fresh(['keyResults', 'allocations']), $board);
    }

    /** @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function updateTarget(int $businessId, User $user, int $targetId, array $data): array
    {
        $target = $this->findTargetForBusiness($businessId, $targetId);
        $board = $this->pipeline->getBoard($businessId, $user, (int) $target->board_id);
        $this->pipeline->ensureCanManageBoard($user, $board);

        $validated = $this->validateTargetPayload($data, $board, $target);
        $target->update($validated);

        if (! empty($data['allocations']) && is_array($data['allocations'])) {
            $this->decomposition->persistAllocations($businessId, $target, $data['allocations'], $user);
        }

        $target->load(['member:id,name,avatar', 'keyResults.member:id,name,avatar', 'allocations']);

        return $this->targetProgress->serializeTargetTree($target, $board);
    }

    public function archiveTarget(int $businessId, User $user, int $targetId): void
    {
        $target = $this->findTargetForBusiness($businessId, $targetId);
        $board = $this->pipeline->getBoard($businessId, $user, (int) $target->board_id);
        $this->pipeline->ensureCanManageBoard($user, $board);

        $target->update(['status' => 'archived']);
        PipelineBoardTarget::query()
            ->where('parent_id', $target->id)
            ->update(['status' => 'archived']);
    }

    /** @param  list<int>|null  $stageIds
     * @return list<int>
     */
    public function resolveStageIds(PipelineBoard $board, ?array $stageIds): array
    {
        $board->loadMissing('stages');
        $all = $board->stages->pluck('id')->map(fn ($id) => (int) $id)->all();
        if ($stageIds === null || $stageIds === []) {
            return $all;
        }

        return array_values(array_intersect(array_map('intval', $stageIds), $all));
    }

    /** @return array<string, mixed> */
    public function defaultChartConfig(PipelineBoard $board): array
    {
        $board->loadMissing('stages');
        $stageIds = $board->stages->pluck('id')->map(fn ($id) => (int) $id)->all();

        return [
            'charts' => [
                ['id' => 'funnel', 'type' => 'bar', 'metric' => 'count', 'stage_ids' => $stageIds],
                ['id' => 'trend', 'type' => 'line', 'metrics' => ['cards_created', 'cards_won', 'cards_lost'], 'stage_ids' => $stageIds],
                ['id' => 'column_throughput', 'type' => 'bar', 'metric' => 'throughput', 'stage_ids' => $stageIds],
            ],
            'funnel_mode' => 'count',
        ];
    }

    protected function findTargetForBusiness(int $businessId, int $targetId): PipelineBoardTarget
    {
        return PipelineBoardTarget::query()
            ->where('business_id', $businessId)
            ->whereKey($targetId)
            ->firstOrFail();
    }

    /** @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function validateTargetPayload(array $data, PipelineBoard $board, ?PipelineBoardTarget $existing = null): array
    {
        $type = $data['type'] ?? $existing?->type;
        $metricKey = $data['metric_key'] ?? $existing?->metric_key;

        if (! in_array($type, ['kpi', 'goal', 'objective', 'key_result'], true)) {
            throw ValidationException::withMessages(['type' => 'Invalid target type.']);
        }

        if (! in_array($metricKey, PipelineBoardProgressMetricsService::METRIC_KEYS, true) && ! $this->columnMetrics->parseStageMetricKey($metricKey)) {
            throw ValidationException::withMessages(['metric_key' => 'Invalid metric key.']);
        }

        $periodType = $data['period_type'] ?? $existing?->period_type ?? 'month';
        $planningLevel = $data['planning_level'] ?? $existing?->planning_level;

        if ($planningLevel && ! $existing) {
            $anchorStart = isset($data['anchor_start'])
                ? Carbon::parse($data['anchor_start'])
                : Carbon::parse($this->decomposition->defaultAnchorStart($planningLevel));
            $anchorEnd = isset($data['anchor_end'])
                ? Carbon::parse($data['anchor_end'])
                : Carbon::parse($this->decomposition->defaultAnchorEnd($planningLevel, $anchorStart));
            $start = $anchorStart->copy()->startOfDay();
            $end = $anchorEnd->copy()->endOfDay();
        } elseif (isset($data['period_start'], $data['period_end'])) {
            $start = Carbon::parse($data['period_start'])->startOfDay();
            $end = Carbon::parse($data['period_end'])->endOfDay();
            $anchorStart = isset($data['anchor_start'])
                ? Carbon::parse($data['anchor_start'])
                : $start->copy();
            $anchorEnd = isset($data['anchor_end'])
                ? Carbon::parse($data['anchor_end'])
                : $end->copy();
        } else {
            [$start, $end] = $this->period->resolvePeriod($periodType, $data['period_from'] ?? null, $data['period_to'] ?? null);
            $anchorStart = isset($data['anchor_start'])
                ? Carbon::parse($data['anchor_start'])
                : ($existing?->anchor_start ? Carbon::parse($existing->anchor_start) : $start->copy());
            $anchorEnd = isset($data['anchor_end'])
                ? Carbon::parse($data['anchor_end'])
                : ($existing?->anchor_end ? Carbon::parse($existing->anchor_end) : $end->copy());
        }

        $scope = $data['scope'] ?? $existing?->scope ?? 'board';
        $memberUserId = $data['member_user_id'] ?? $existing?->member_user_id;
        $stageId = $data['stage_id'] ?? $existing?->stage_id;

        if ($type !== 'key_result' && empty($stageId) && empty($existing?->stage_id)) {
            throw ValidationException::withMessages(['stage_id' => 'Select a board column for this target.']);
        }

        if ($scope === 'member' && ! $memberUserId) {
            throw ValidationException::withMessages(['member_user_id' => 'Select a team member for member-scoped targets.']);
        }

        if ($type === 'key_result' && empty($data['parent_id']) && ! $existing?->parent_id) {
            throw ValidationException::withMessages(['parent_id' => 'Key results must belong to an objective.']);
        }

        return [
            'type' => $type,
            'goal_tag' => $data['goal_tag'] ?? $existing?->goal_tag ?? $type,
            'title' => $data['title'] ?? $existing?->title,
            'description' => $data['description'] ?? $existing?->description,
            'metric_key' => $metricKey,
            'target_value' => (float) ($data['target_value'] ?? $existing?->target_value ?? 0),
            'unit' => $data['unit'] ?? $existing?->unit ?? 'count',
            'period_type' => $periodType,
            'planning_level' => $planningLevel,
            'anchor_start' => $anchorStart->toDateString(),
            'anchor_end' => $anchorEnd->toDateString(),
            'period_start' => $start->toDateString(),
            'period_end' => $end->toDateString(),
            'scope' => $scope,
            'member_user_id' => $scope === 'member' ? (int) $memberUserId : null,
            'stage_id' => $stageId ? (int) $stageId : null,
            'weight' => (int) ($data['weight'] ?? $existing?->weight ?? 100),
            'status' => $data['status'] ?? $existing?->status ?? 'active',
            'decomposition_mode' => $data['decomposition_mode'] ?? $existing?->decomposition_mode ?? 'hybrid',
            'parent_id' => $data['parent_id'] ?? $existing?->parent_id,
        ];
    }
}
