<?php

declare(strict_types=1);

namespace App\Services\Pipeline;

use App\Models\PipelineBoard;
use App\Models\PipelineBoardTarget;
use App\Models\User;
use App\Services\PipelineService;
use Carbon\Carbon;

class PipelineBoardTargetProgressService
{
    public function __construct(
        protected PipelineService $pipeline,
        protected PipelineBoardProgressMetricsService $metrics,
        protected PipelineGoalDecompositionService $decomposition,
    ) {}

    /** @return list<array<string, mixed>> */
    public function listTargetsWithProgress(
        int $businessId,
        User $user,
        int $boardId,
        Carbon $start,
        Carbon $end,
        ?string $viewPeriodType = null,
    ): array {
        $board = $this->pipeline->getBoard($businessId, $user, $boardId);

        $viewStart = $start->copy()->startOfDay();
        $viewEnd = $end->copy()->endOfDay();

        return PipelineBoardTarget::query()
            ->where('board_id', $board->id)
            ->where('status', '!=', 'archived')
            ->where('type', '!=', 'key_result')
            ->where(function ($q) use ($viewStart, $viewEnd) {
                $q->where('period_start', '<=', $viewEnd->toDateTimeString())
                    ->where('period_end', '>=', $viewStart->toDateTimeString());
            })
            ->with(['member:id,name,avatar', 'keyResults.member:id,name,avatar', 'allocations'])
            ->orderBy('type')
            ->orderBy('title')
            ->get()
            ->map(fn (PipelineBoardTarget $t) => $this->serializeTargetTree($t, $board, $start, $end, $viewPeriodType))
            ->values()
            ->all();
    }

    /** @return array<string, mixed> */
    public function serializeTargetTree(
        PipelineBoardTarget $target,
        PipelineBoard $board,
        ?Carbon $start = null,
        ?Carbon $end = null,
        ?string $viewPeriodType = null,
    ): array {
        $periodStart = $start ?? Carbon::parse($target->period_start)->startOfDay();
        $periodEnd = $end ?? Carbon::parse($target->period_end)->endOfDay();
        $memberId = $target->scope === 'member' ? (int) $target->member_user_id : null;
        $actual = $this->metrics->computeMetricValue($board, $target->metric_key, $periodStart, $periodEnd, $memberId);
        $progressPercent = $this->progressPercent($actual, (float) $target->target_value);
        $paceStatus = $this->paceStatus($actual, (float) $target->target_value, $periodStart, $periodEnd, $target->metric_key);
        $periodSlice = null;

        if ($start !== null && $end !== null) {
            $periodSlice = $this->buildPeriodSlice($target, $board, $periodStart, $periodEnd, $viewPeriodType);
            if ($periodSlice !== null) {
                $actual = $periodSlice['actual_value'];
                $progressPercent = $periodSlice['progress_percent'];
                $paceStatus = $periodSlice['pace_status'];
            }
        }

        $payload = [
            'id' => (int) $target->id,
            'parent_id' => $target->parent_id ? (int) $target->parent_id : null,
            'type' => $target->type,
            'title' => $target->title,
            'description' => $target->description,
            'metric_key' => $target->metric_key,
            'target_value' => (float) $target->target_value,
            'actual_value' => $actual,
            'unit' => $target->unit,
            'period_type' => $target->period_type,
            'period_start' => $target->period_start?->toDateString(),
            'period_end' => $target->period_end?->toDateString(),
            'scope' => $target->scope,
            'member_user_id' => $target->member_user_id ? (int) $target->member_user_id : null,
            'member' => $target->member ? [
                'id' => (int) $target->member->id,
                'name' => $target->member->name,
                'avatar' => $target->member->avatar,
            ] : null,
            'weight' => (int) $target->weight,
            'status' => $target->status,
            'progress_percent' => $progressPercent,
            'pace_status' => $paceStatus,
            'planning_level' => $target->planning_level,
            'anchor_start' => $target->anchor_start?->toDateString(),
            'anchor_end' => $target->anchor_end?->toDateString(),
            'stage_id' => $target->stage_id ? (int) $target->stage_id : null,
            'goal_tag' => $target->goal_tag,
            'decomposition_mode' => $target->decomposition_mode,
            'allocations' => $target->relationLoaded('allocations')
                ? $this->decomposition->allocationsForTarget($target)
                : [],
            'key_results' => [],
        ];

        if ($periodSlice !== null) {
            $payload['period_slice'] = $periodSlice;
        }

        if ($target->relationLoaded('keyResults')) {
            $payload['key_results'] = $target->keyResults
                ->where('status', '!=', 'archived')
                ->map(fn (PipelineBoardTarget $kr) => $this->serializeTargetTree($kr, $board, $periodStart, $periodEnd, $viewPeriodType))
                ->values()
                ->all();

            if ($target->type === 'objective' && count($payload['key_results']) > 0 && ! isset($payload['period_slice'])) {
                $payload['progress_percent'] = round(
                    collect($payload['key_results'])->avg('progress_percent') ?? 0,
                    1,
                );
            }
        }

        return $payload;
    }

    public function progressPercent(float $actual, float $target): float
    {
        if ($target <= 0) {
            return 0.0;
        }

        return min(100.0, round(($actual / $target) * 100, 1));
    }

    public function paceStatus(
        float $actual,
        float $target,
        Carbon $periodStart,
        Carbon $periodEnd,
        string $metricKey,
    ): string {
        if ($target <= 0) {
            return 'on_track';
        }

        if ($actual >= $target) {
            return 'achieved';
        }

        $lowerIsBetter = $metricKey === 'avg_cycle_days';
        $totalDays = max(1, $periodStart->diffInDays($periodEnd) + 1);
        $elapsedDays = max(1, $periodStart->diffInDays(min(now(), $periodEnd)) + 1);
        $expectedPace = ($target / $totalDays) * $elapsedDays;

        if ($lowerIsBetter) {
            if ($actual <= $expectedPace * 0.9) {
                return 'on_track';
            }
            if ($actual <= $expectedPace * 1.1) {
                return 'at_risk';
            }

            return 'behind';
        }

        if ($actual >= $expectedPace * 0.9) {
            return 'on_track';
        }
        if ($actual >= $expectedPace * 0.7) {
            return 'at_risk';
        }

        return 'behind';
    }

    /** @return array<string, mixed>|null */
    public function buildPeriodSlice(
        PipelineBoardTarget $target,
        PipelineBoard $board,
        Carbon $viewStart,
        Carbon $viewEnd,
        ?string $viewPeriodType,
    ): ?array {
        $memberId = $target->scope === 'member' ? (int) $target->member_user_id : null;
        $stageId = $target->stage_id ? (int) $target->stage_id : null;
        $allocations = $target->relationLoaded('allocations')
            ? $target->allocations
            : $target->allocations()->get();

        if ($allocations->isEmpty()) {
            return $this->buildFallbackPeriodSlice($target, $board, $viewStart, $viewEnd, $memberId, $viewPeriodType);
        }

        $preferredLevel = $viewPeriodType
            ? $this->decomposition->viewPeriodToPlanningLevel($viewPeriodType)
            : null;

        $rows = $this->decomposition->resolveSliceAllocations(
            $allocations,
            $viewStart,
            $viewEnd,
            $preferredLevel,
            $stageId,
            $memberId,
        );

        $expected = 0.0;
        $planningLevel = $preferredLevel ?? 'month';

        if ($rows !== []) {
            $expected = $this->sumProratedAllocationExpected($rows, $viewStart, $viewEnd);
            $planningLevel = $preferredLevel ?? $rows[0]->planning_level;
        } elseif ($viewPeriodType === 'custom' || $preferredLevel === null) {
            $expected = $this->decomposition->sumDailyExpectedInView(
                $allocations,
                $viewStart,
                $viewEnd,
                $stageId,
                $memberId,
            );
            $planningLevel = 'day';
        }

        if ($expected <= 0) {
            return $this->buildFallbackPeriodSlice($target, $board, $viewStart, $viewEnd, $memberId, $viewPeriodType);
        }

        $sliceStart = $viewStart->copy()->startOfDay();
        $sliceEnd = $viewEnd->copy()->endOfDay();

        $actual = $this->metrics->computeMetricValue($board, $target->metric_key, $viewStart, $viewEnd, $memberId);
        $expectedToDate = $this->decomposition->expectedToDate($expected, $sliceStart, $sliceEnd, now());

        return $this->periodSlicePayload(
            $target,
            $planningLevel,
            $sliceStart,
            $sliceEnd,
            $viewPeriodType,
            $expected,
            $expectedToDate,
            $actual,
        );
    }

    /**
     * @param  list<\App\Models\PipelineBoardTargetAllocation>  $rows
     */
    public function sumProratedAllocationExpected(array $rows, Carbon $viewStart, Carbon $viewEnd): float
    {
        $expected = 0.0;

        foreach ($rows as $row) {
            $rowStart = Carbon::parse($row->period_start)->startOfDay();
            $rowEnd = Carbon::parse($row->period_end)->endOfDay();
            $overlapStart = $viewStart->greaterThan($rowStart) ? $viewStart->copy() : $rowStart->copy();
            $overlapEnd = $viewEnd->lessThan($rowEnd) ? $viewEnd->copy() : $rowEnd->copy();

            if ($overlapStart->gt($overlapEnd)) {
                continue;
            }

            $rowDays = max(1, $this->decomposition->inclusiveDays($rowStart, $rowEnd));
            $overlapDays = max(1, $this->decomposition->inclusiveDays($overlapStart, $overlapEnd));
            $expected += (float) $row->expected_value * ($overlapDays / $rowDays);
        }

        return $expected;
    }

    /** @return array<string, mixed>|null */
    public function buildFallbackPeriodSlice(
        PipelineBoardTarget $target,
        PipelineBoard $board,
        Carbon $viewStart,
        Carbon $viewEnd,
        ?int $memberId,
        ?string $viewPeriodType,
    ): ?array {
        $anchorStart = Carbon::parse($target->anchor_start ?? $target->period_start)->startOfDay();
        $anchorEnd = Carbon::parse($target->anchor_end ?? $target->period_end)->endOfDay();

        if ($viewEnd->lt($anchorStart) || $viewStart->gt($anchorEnd)) {
            return null;
        }

        $sliceStart = $viewStart->gt($anchorStart) ? $viewStart->copy() : $anchorStart->copy();
        $sliceEnd = $viewEnd->lt($anchorEnd) ? $viewEnd->copy() : $anchorEnd->copy();
        $totalDays = max(1, $this->decomposition->inclusiveDays($anchorStart, $anchorEnd));
        $sliceDays = max(1, $this->decomposition->inclusiveDays($sliceStart, $sliceEnd));
        $expected = (float) $target->target_value * ($sliceDays / $totalDays);
        $actual = $this->metrics->computeMetricValue($board, $target->metric_key, $viewStart, $viewEnd, $memberId);
        $expectedToDate = $this->decomposition->expectedToDate($expected, $sliceStart, $sliceEnd, now());
        $planningLevel = $viewPeriodType
            ? ($this->decomposition->viewPeriodToPlanningLevel($viewPeriodType) ?? 'month')
            : ($target->planning_level ?? 'month');

        return $this->periodSlicePayload(
            $target,
            $planningLevel,
            $sliceStart,
            $sliceEnd,
            $viewPeriodType,
            $expected,
            $expectedToDate,
            $actual,
        );
    }

    /** @return array<string, mixed> */
    public function periodSlicePayload(
        PipelineBoardTarget $target,
        string $planningLevel,
        Carbon $sliceStart,
        Carbon $sliceEnd,
        ?string $viewPeriodType,
        float $expected,
        float $expectedToDate,
        float $actual,
    ): array {
        return [
            'planning_level' => $planningLevel,
            'period_start' => $sliceStart->toDateString(),
            'period_end' => $sliceEnd->toDateString(),
            'view_period_type' => $viewPeriodType,
            'expected_value' => round($expected, 4),
            'expected_to_date' => round($expectedToDate, 4),
            'horizon_expected_to_date' => $this->decomposition->horizonExpectedToDate($target),
            'actual_value' => $actual,
            'progress_percent' => $this->progressPercent($actual, $expected),
            'pace_status' => $this->paceStatus($actual, $expected, $sliceStart, $sliceEnd, $target->metric_key),
            'root_target_value' => (float) $target->target_value,
        ];
    }

    /** @param  list<array<string, mixed>>  $targets
     * @return list<array<string, mixed>>
     */
    public function computePaceAlerts(array $targets): array
    {
        $alerts = [];
        foreach ($targets as $target) {
            if (in_array($target['pace_status'] ?? '', ['behind', 'at_risk'], true)) {
                $alerts[] = [
                    'target_id' => $target['id'],
                    'title' => $target['title'],
                    'pace_status' => $target['pace_status'],
                    'progress_percent' => $target['progress_percent'],
                ];
            }
        }

        return $alerts;
    }

    /**
     * Public snapshot of a target for HR performance evaluation (member goals).
     *
     * @return array<string, mixed>
     */
    public function serializeTargetForHr(
        PipelineBoardTarget $target,
        PipelineBoard $board,
        ?Carbon $start = null,
        ?Carbon $end = null,
        ?string $viewPeriodType = null,
    ): array {
        return $this->serializeTargetTree($target, $board, $start, $end, $viewPeriodType);
    }
}
