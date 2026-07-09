<?php

declare(strict_types=1);

namespace App\Services\Pipeline;

use App\Models\PipelineBoard;
use App\Models\PipelineBoardTarget;
use App\Models\PipelineBoardTargetAllocation;
use App\Models\PipelineBoardTargetEvent;
use App\Models\User;
use Carbon\Carbon;
use Carbon\CarbonPeriod;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/** @phpstan-type PeriodSlice array{
 *     planning_level: string,
 *     period_start: string,
 *     period_end: string,
 *     view_period_type: ?string,
 *     expected_value: float,
 *     expected_to_date: float,
 *     actual_value: float,
 *     progress_percent: float,
 *     pace_status: string,
 *     root_target_value: float,
 * }
 */

class PipelineGoalDecompositionService
{
    public const PLANNING_LEVELS = ['decade', 'five_year', 'year', 'quarter', 'month', 'week', 'day'];

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

        return $target->allocations()
            ->orderBy('period_start')
            ->get()
            ->map(function (PipelineBoardTargetAllocation $row) use ($asOf) {
                $expectedToDate = $this->expectedToDate(
                    (float) $row->expected_value,
                    Carbon::parse($row->period_start)->startOfDay(),
                    Carbon::parse($row->period_end)->endOfDay(),
                    $asOf,
                );

                return [
                    'id' => (int) $row->id,
                    'planning_level' => $row->planning_level,
                    'period_start' => $row->period_start?->toDateString(),
                    'period_end' => $row->period_end?->toDateString(),
                    'expected_value' => (float) $row->expected_value,
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

        $totalDays = max(1, $start->diffInDays($end) + 1);
        $elapsed = max(0, min($totalDays, $start->diffInDays(min($asOf->copy()->endOfDay(), $end)) + 1));

        return ($expected / $totalDays) * $elapsed;
    }

    public function viewPeriodToPlanningLevel(string $periodType): ?string
    {
        return match ($periodType) {
            'day' => 'day',
            'week' => 'week',
            'month' => 'month',
            'quarter' => 'quarter',
            'year' => 'year',
            default => null,
        };
    }

    /**
     * Resolve allocation rows that represent the active view window for a target.
     *
     * @param  Collection<int, PipelineBoardTargetAllocation>  $allocations
     * @return list<PipelineBoardTargetAllocation>
     */
    public function resolveSliceAllocations(
        Collection $allocations,
        Carbon $viewStart,
        Carbon $viewEnd,
        ?string $preferredLevel,
        ?int $stageId,
        ?int $memberUserId,
    ): array {
        $overlapping = $allocations->filter(function (PipelineBoardTargetAllocation $row) use (
            $viewStart,
            $viewEnd,
            $stageId,
            $memberUserId,
        ) {
            if ($stageId && $row->stage_id && (int) $row->stage_id !== $stageId) {
                return false;
            }

            if ($memberUserId !== null) {
                if ($row->member_user_id && (int) $row->member_user_id !== $memberUserId) {
                    return false;
                }
            } elseif ($row->member_user_id) {
                return false;
            }

            $periodStart = Carbon::parse($row->period_start)->startOfDay();
            $periodEnd = Carbon::parse($row->period_end)->endOfDay();

            return $periodStart->lte($viewEnd) && $periodEnd->gte($viewStart);
        });

        if ($overlapping->isEmpty()) {
            return [];
        }

        if ($preferredLevel) {
            $atLevel = $overlapping->where('planning_level', $preferredLevel)->values();
            if ($atLevel->isNotEmpty()) {
                return $atLevel->all();
            }
        }

        foreach (array_reverse(self::PLANNING_LEVELS) as $level) {
            $atLevel = $overlapping->where('planning_level', $level)->values();
            if ($atLevel->isNotEmpty()) {
                return $atLevel->all();
            }
        }

        return [];
    }

    /**
     * Sum expected allocation for a custom or multi-row view by using daily nodes when present.
     *
     * @param  Collection<int, PipelineBoardTargetAllocation>  $allocations
     */
    public function sumDailyExpectedInView(
        Collection $allocations,
        Carbon $viewStart,
        Carbon $viewEnd,
        ?int $stageId,
        ?int $memberUserId,
    ): float {
        $daily = $allocations->filter(function (PipelineBoardTargetAllocation $row) use (
            $viewStart,
            $viewEnd,
            $stageId,
            $memberUserId,
        ) {
            if ($row->planning_level !== 'day') {
                return false;
            }

            if ($stageId && $row->stage_id && (int) $row->stage_id !== $stageId) {
                return false;
            }

            if ($memberUserId !== null) {
                if ($row->member_user_id && (int) $row->member_user_id !== $memberUserId) {
                    return false;
                }
            } elseif ($row->member_user_id) {
                return false;
            }

            $day = Carbon::parse($row->period_start)->startOfDay();

            return $day->gte($viewStart->copy()->startOfDay()) && $day->lte($viewEnd->copy()->endOfDay());
        });

        return (float) $daily->sum('expected_value');
    }

    public function defaultAnchorStart(string $planningLevel, ?int $year = null): string
    {
        $year = $year ?? (int) now()->year;

        return match ($planningLevel) {
            'decade' => Carbon::create(((int) floor($year / 10)) * 10, 1, 1)->toDateString(),
            'five_year' => Carbon::create($year - ($year % 5), 1, 1)->toDateString(),
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

    /** @param  list<int>  $stageIds
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
        $levels = array_merge([$rootLevel], $this->childLevelsBelow($rootLevel));
        $stageWeights = $mode === 'equal'
            ? array_fill_keys($stageIds, 1 / max(1, count($stageIds)))
            : $this->columnMetrics->columnThroughputWeights($board, $stageIds);

        $nodes = [];
        $rootNodes = $this->periodSlices($rootLevel, $anchorStart, $anchorEnd);
        $rootWeightSum = max(1, count($rootNodes));
        $perRoot = $targetValue / $rootWeightSum;

        foreach ($rootNodes as $slice) {
            foreach ($stageIds as $stageId) {
                $stageShare = $perRoot * ($stageWeights[$stageId] ?? (1 / max(1, count($stageIds))));
                $memberShares = $this->distributeAmongMembers($stageShare, $memberIds);

                foreach ($memberShares as $share) {
                    $memberId = $share['member_id'];
                    $value = $share['value'];
                    $nodes[] = $this->nodePayload($rootLevel, $slice['start'], $slice['end'], $value, $stageId, $memberId);

                    foreach ($this->childLevelsBelow($rootLevel) as $childLevel) {
                        $childSlices = $this->periodSlices($childLevel, $slice['start'], $slice['end']);
                        $childSum = max(1, count($childSlices));
                        foreach ($childSlices as $child) {
                            $childValue = $value / $childSum;
                            $nodes[] = $this->nodePayload($childLevel, $child['start'], $child['end'], $childValue, $stageId, $memberId);
                        }
                    }
                }
            }
        }

        return $nodes;
    }

    /** @return list<array{start: Carbon, end: Carbon, label: string}> */
    protected function periodSlices(string $level, Carbon $start, Carbon $end): array
    {
        $slices = [];
        $cursor = $start->copy()->startOfDay();

        while ($cursor->lte($end)) {
            $sliceEnd = match ($level) {
                'decade' => $cursor->copy()->addYears(10)->subDay()->endOfDay(),
                'five_year' => $cursor->copy()->addYears(5)->subDay()->endOfDay(),
                'year' => $cursor->copy()->endOfYear(),
                'quarter' => $cursor->copy()->lastOfQuarter()->endOfDay(),
                'month' => $cursor->copy()->endOfMonth(),
                'week' => $cursor->copy()->endOfWeek(),
                'day' => $cursor->copy()->endOfDay(),
                default => $cursor->copy()->endOfMonth(),
            };
            if ($sliceEnd->gt($end)) {
                $sliceEnd = $end->copy()->endOfDay();
            }

            $label = match ($level) {
                'quarter' => 'Q'.$cursor->quarter.' '.$cursor->year,
                'month' => $cursor->format('M Y'),
                'week' => 'W'.$cursor->isoWeek().' '.$cursor->year,
                'day' => $cursor->toDateString(),
                default => $cursor->year.($level === 'year' ? '' : ''),
            };

            $slices[] = ['start' => $cursor->copy(), 'end' => $sliceEnd, 'label' => $label];

            $cursor = match ($level) {
                'decade' => $cursor->copy()->addYears(10)->startOfDay(),
                'five_year' => $cursor->copy()->addYears(5)->startOfDay(),
                'year' => $cursor->copy()->addYear()->startOfYear(),
                'quarter' => $cursor->copy()->addQuarter()->startOfQuarter(),
                'month' => $cursor->copy()->addMonth()->startOfMonth(),
                'week' => $cursor->copy()->addWeek()->startOfWeek(),
                'day' => $cursor->copy()->addDay()->startOfDay(),
                default => $cursor->copy()->addMonth()->startOfMonth(),
            };
        }

        return $slices;
    }

    /** @return array<string, mixed> */
    protected function nodePayload(
        string $level,
        Carbon $start,
        Carbon $end,
        float $expected,
        int $stageId,
        ?int $memberId,
    ): array {
        return [
            'planning_level' => $level,
            'period_start' => $start->toDateString(),
            'period_end' => $end->toDateString(),
            'expected_value' => round($expected, 4),
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
