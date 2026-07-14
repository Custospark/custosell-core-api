<?php

declare(strict_types=1);

namespace App\Services\Pipeline;

use App\Models\PipelineBoard;
use App\Models\PipelineBoardTarget;
use App\Models\PipelineBoardTargetAllocation;
use App\Models\PipelineBoardTargetEvent;
use App\Models\User;
use App\Services\Pipeline\Concerns\PipelineGoalDayWeighting;
use App\Services\Pipeline\Concerns\PipelineGoalPeriodSlicing;
use App\Services\Pipeline\Concerns\PipelineGoalSliceResolution;
use Carbon\Carbon;
use Illuminate\Validation\ValidationException;

/** @phpstan-type PeriodSlice array{
 *     planning_level: string,
 *     period_start: string,
 *     period_end: string,
 *     view_period_type: ?string,
 *     expected_value: float,
 *     expected_to_date: float,
 *     horizon_expected_to_date: ?float,
 *     actual_value: float,
 *     progress_percent: float,
 *     pace_status: string,
 *     root_target_value: float,
 * }
 */

class PipelineGoalDecompositionService
{
    use PipelineGoalDayWeighting;
    use PipelineGoalPeriodSlicing;
    use PipelineGoalSliceResolution;

    public const PLANNING_LEVELS = ['decade', 'five_year', 'year', 'quarter', 'month', 'week', 'day'];

    public const LONG_HORIZON_LEVELS = ['decade', 'five_year', 'year'];

    public function __construct(
        protected PipelineColumnMetricsService $columnMetrics,
    ) {}

    /** @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function preview(int $businessId, PipelineBoard $board, array $data): array
    {
        $planningLevel = $data['planning_level'] ?? 'year';
        $targetValue = (float) ($data['target_value'] ?? 0);
        $stageIds = array_map('intval', $data['stage_ids'] ?? []);
        $anchorStart = Carbon::parse($data['anchor_start'] ?? $this->defaultAnchorStart($planningLevel));
        $anchorEnd = Carbon::parse($data['anchor_end'] ?? $this->defaultAnchorEnd($planningLevel, $anchorStart));
        $memberIds = array_map('intval', $data['member_user_ids'] ?? []);
        $mode = $data['decomposition_mode'] ?? 'hybrid';

        if ($stageIds === []) {
            $stageIds = $board->stages()->pluck('id')->map(fn ($id) => (int) $id)->all();
        }

        if ($stageIds === []) {
            throw ValidationException::withMessages(['stage_ids' => 'Select at least one board column.']);
        }

        $tree = $this->buildDecompositionTree(
            $board,
            $planningLevel,
            $anchorStart,
            $anchorEnd,
            $targetValue,
            $stageIds,
            $memberIds,
            $mode,
        );

        return [
            'planning_level' => $planningLevel,
            'anchor_start' => $anchorStart->toDateString(),
            'anchor_end' => $anchorEnd->toDateString(),
            'target_value' => $targetValue,
            'stage_ids' => $stageIds,
            'decomposition_mode' => $mode,
            'nodes' => $tree,
        ];
    }

    /** @param  list<array<string, mixed>>  $nodes */
    public function persistAllocations(
        int $businessId,
        PipelineBoardTarget $target,
        array $nodes,
        User $user,
    ): void {
        PipelineBoardTargetAllocation::query()
            ->where('target_id', $target->id)
            ->delete();

        $this->insertNodes($businessId, $target, $nodes);

        PipelineBoardTargetEvent::create([
            'business_id' => $businessId,
            'target_id' => $target->id,
            'user_id' => $user->id,
            'event_type' => 'decomposition_saved',
            'payload' => ['node_count' => count($nodes)],
        ]);
    }

    /** @return list<array<string, mixed>> */
    public function allocationsForTarget(PipelineBoardTarget $target, ?Carbon $asOf = null): array
    {
        $asOf = $asOf ?? now();
        $horizonStart = Carbon::parse($target->anchor_start ?? $target->period_start)->startOfDay();
        $horizonEnd = Carbon::parse($target->anchor_end ?? $target->period_end)->startOfDay();
        $horizonDays = max(1, $this->inclusiveDays($horizonStart, $horizonEnd));
        $targetValue = (float) $target->target_value;

        return $target->allocations()
            ->orderBy('period_start')
            ->get()
            ->map(function (PipelineBoardTargetAllocation $row) use (
                $asOf,
                $horizonStart,
                $horizonEnd,
                $horizonDays,
                $targetValue,
            ) {
                $periodStart = Carbon::parse($row->period_start)->startOfDay();
                $periodEnd = Carbon::parse($row->period_end)->endOfDay();
                $expectedToDate = $this->expectedToDate(
                    (float) $row->expected_value,
                    $periodStart,
                    $periodEnd,
                    $asOf,
                );

                return [
                    'id' => (int) $row->id,
                    'planning_level' => $row->planning_level,
                    'period_start' => $row->period_start?->toDateString(),
                    'period_end' => $row->period_end?->toDateString(),
                    'expected_value' => (float) $row->expected_value,
                    'cumulative_expected' => $this->cumulativeExpectedThrough(
                        $targetValue,
                        $horizonStart,
                        $horizonEnd,
                        Carbon::parse($row->period_end),
                        $horizonDays,
                    ),
                    'expected_to_date' => round($expectedToDate, 4),
                    'actual_value' => (float) $row->actual_value,
                    'stage_id' => $row->stage_id ? (int) $row->stage_id : null,
                    'member_user_id' => $row->member_user_id ? (int) $row->member_user_id : null,
                    'weight' => (int) $row->weight,
                    'is_override' => (bool) $row->is_override,
                    'progress_percent' => $row->expected_value > 0
                        ? min(100, round(($row->actual_value / $row->expected_value) * 100, 1))
                        : 0,
                ];
            })
            ->values()
            ->all();
    }

    public function expectedToDate(float $expected, Carbon $start, Carbon $end, Carbon $asOf): float
    {
        if ($expected <= 0) {
            return 0.0;
        }

        $totalDays = max(1, $this->inclusiveDays($start, $end));
        $clippedAsOf = min($asOf->copy()->endOfDay(), $end);
        $elapsed = max(0, min($totalDays, $this->inclusiveDays($start, $clippedAsOf)));

        return ($expected / $totalDays) * $elapsed;
    }

    /**
     * Day-weighted share of T from anchor_start through min(now, anchor_end).
     * Only meaningful for decade / five_year / year; returns null otherwise.
     */
    public function horizonExpectedToDate(PipelineBoardTarget $target, ?Carbon $asOf = null): ?float
    {
        if (! in_array($target->planning_level, self::LONG_HORIZON_LEVELS, true)) {
            return null;
        }

        $asOf = $asOf ?? now();
        $anchorStart = Carbon::parse($target->anchor_start ?? $target->period_start)->startOfDay();
        $anchorEnd = Carbon::parse($target->anchor_end ?? $target->period_end)->startOfDay();
        $horizonDays = max(1, $this->inclusiveDays($anchorStart, $anchorEnd));
        $through = $asOf->copy()->startOfDay()->min($anchorEnd);

        if ($through->lt($anchorStart)) {
            return 0.0;
        }

        return $this->cumulativeExpectedThrough(
            (float) $target->target_value,
            $anchorStart,
            $anchorEnd,
            $through,
            $horizonDays,
        );
    }

    public function defaultAnchorStart(string $planningLevel, ?int $year = null): string
    {
        $year = $year ?? (int) now()->year;

        return match ($planningLevel) {
            // Rolling horizons aligned with FE: Jan 1 of current year (not calendar decade / 5-year floors).
            'decade', 'five_year' => Carbon::create($year, 1, 1)->toDateString(),
            'year' => Carbon::create($year, 1, 1)->toDateString(),
            'quarter' => now()->copy()->firstOfQuarter()->toDateString(),
            'month' => now()->copy()->startOfMonth()->toDateString(),
            'week' => now()->copy()->startOfWeek()->toDateString(),
            'day' => now()->toDateString(),
            default => Carbon::create($year, 1, 1)->toDateString(),
        };
    }

    public function defaultAnchorEnd(string $planningLevel, Carbon $anchorStart): string
    {
        return match ($planningLevel) {
            // decade: Dec 31 of year+9; five_year: Dec 31 of year+4
            'decade' => $anchorStart->copy()->addYears(10)->subDay()->toDateString(),
            'five_year' => $anchorStart->copy()->addYears(5)->subDay()->toDateString(),
            'year' => $anchorStart->copy()->endOfYear()->toDateString(),
            'quarter' => $anchorStart->copy()->lastOfQuarter()->toDateString(),
            'month' => $anchorStart->copy()->endOfMonth()->toDateString(),
            'week' => $anchorStart->copy()->endOfWeek()->toDateString(),
            'day' => $anchorStart->toDateString(),
            default => $anchorStart->copy()->endOfYear()->toDateString(),
        };
    }

    /** @return list<string> */
    public function childLevelsBelow(string $planningLevel): array
    {
        $index = array_search($planningLevel, self::PLANNING_LEVELS, true);
        if ($index === false) {
            return [];
        }

        return array_slice(self::PLANNING_LEVELS, $index + 1);
    }

    /** Immediate next planning level under $planningLevel, or null at leaf. */
    public function nextChildLevel(string $planningLevel): ?string
    {
        $children = $this->childLevelsBelow($planningLevel);

        return $children[0] ?? null;
    }

    /**
     * @param  list<int>  $stageIds
     * @param  list<int>  $memberIds
     * @return list<array<string, mixed>>
     */
    protected function buildDecompositionTree(
        PipelineBoard $board,
        string $rootLevel,
        Carbon $anchorStart,
        Carbon $anchorEnd,
        float $targetValue,
        array $stageIds,
        array $memberIds,
        string $mode,
    ): array {
        $stageWeights = $mode === 'equal'
            ? array_fill_keys($stageIds, 1 / max(1, count($stageIds)))
            : $this->columnMetrics->columnThroughputWeights($board, $stageIds);

        $horizonStart = $anchorStart->copy()->startOfDay();
        $horizonEnd = $anchorEnd->copy()->endOfDay();
        $horizonDays = max(1, $this->inclusiveDays($horizonStart, $horizonEnd));

        $nodes = [];
        $rootSlices = $this->periodSlices($rootLevel, $horizonStart, $horizonEnd);

        foreach ($rootSlices as $slice) {
            // Root: T × days(slice ∩ horizon) / days(horizon) — not equal bucket counts.
            $rootTimeShare = $targetValue * $this->dayWeightedShare(
                $slice['start'],
                $slice['end'],
                $horizonStart,
                $horizonEnd,
                $horizonDays,
            );

            foreach ($stageIds as $stageId) {
                $stageShare = $rootTimeShare * ($stageWeights[$stageId] ?? (1 / max(1, count($stageIds))));
                $memberShares = $this->distributeAmongMembers($stageShare, $memberIds);

                foreach ($memberShares as $share) {
                    $this->appendCascadeNodes(
                        $nodes,
                        $rootLevel,
                        $slice['start'],
                        $slice['end'],
                        $share['value'],
                        $stageId,
                        $share['member_id'],
                        $targetValue,
                        $horizonStart,
                        $horizonEnd,
                        $horizonDays,
                    );
                }
            }
        }

        return $nodes;
    }

    /**
     * Cascade year→quarter→month→week→day from parent (day-weighted), not flat root÷N.
     *
     * @param  list<array<string, mixed>>  $nodes
     */
    protected function appendCascadeNodes(
        array &$nodes,
        string $level,
        Carbon $start,
        Carbon $end,
        float $parentShare,
        int $stageId,
        ?int $memberId,
        float $rootTargetValue,
        Carbon $horizonStart,
        Carbon $horizonEnd,
        int $horizonDays,
    ): void {
        $nodes[] = $this->nodePayload(
            $level,
            $start,
            $end,
            $parentShare,
            $stageId,
            $memberId,
            $this->cumulativeExpectedThrough(
                $rootTargetValue,
                $horizonStart,
                $horizonEnd,
                $end,
                $horizonDays,
            ),
        );

        $childLevel = $this->nextChildLevel($level);
        if ($childLevel === null || $parentShare <= 0) {
            return;
        }

        $parentDays = max(1, $this->inclusiveDays($start, $end));
        $childSlices = $this->periodSlices($childLevel, $start, $end);

        foreach ($childSlices as $child) {
            $childShare = $parentShare * $this->dayWeightedShare(
                $child['start'],
                $child['end'],
                $start,
                $end,
                $parentDays,
            );

            $this->appendCascadeNodes(
                $nodes,
                $childLevel,
                $child['start'],
                $child['end'],
                $childShare,
                $stageId,
                $memberId,
                $rootTargetValue,
                $horizonStart,
                $horizonEnd,
                $horizonDays,
            );
        }
    }

    /** @return array<string, mixed> */
    protected function nodePayload(
        string $level,
        Carbon $start,
        Carbon $end,
        float $expected,
        int $stageId,
        ?int $memberId,
        float $cumulativeExpected = 0.0,
    ): array {
        return [
            'planning_level' => $level,
            'period_start' => $start->toDateString(),
            'period_end' => $end->toDateString(),
            'expected_value' => round($expected, 4),
            'cumulative_expected' => round($cumulativeExpected, 4),
            'stage_id' => $stageId,
            'member_user_id' => $memberId,
            'weight' => 100,
            'is_override' => false,
        ];
    }

    /** @param  list<int>  $memberIds
     * @return list<array{member_id: ?int, value: float}>
     */
    protected function distributeAmongMembers(float $value, array $memberIds): array
    {
        if ($memberIds === []) {
            return [['member_id' => null, 'value' => $value]];
        }

        $share = $value / count($memberIds);
        $out = [];
        foreach ($memberIds as $id) {
            $out[] = ['member_id' => (int) $id, 'value' => $share];
        }

        return $out;
    }

    /** @param  list<array<string, mixed>>  $nodes */
    protected function insertNodes(int $businessId, PipelineBoardTarget $target, array $nodes): void
    {
        foreach ($nodes as $node) {
            PipelineBoardTargetAllocation::create([
                'business_id' => $businessId,
                'target_id' => $target->id,
                'stage_id' => $node['stage_id'] ?? null,
                'planning_level' => $node['planning_level'],
                'period_start' => $node['period_start'],
                'period_end' => $node['period_end'],
                'expected_value' => $node['expected_value'],
                'actual_value' => 0,
                'member_user_id' => $node['member_user_id'] ?? null,
                'weight' => (int) ($node['weight'] ?? 100),
                'is_override' => (bool) ($node['is_override'] ?? false),
            ]);
        }
    }
}
