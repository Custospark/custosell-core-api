<?php

declare(strict_types=1);

namespace App\Services\Pipeline\Concerns;

use App\Models\PipelineBoardTargetAllocation;
use App\Services\Pipeline\PipelineGoalDecompositionService;
use Carbon\Carbon;
use Illuminate\Support\Collection;

trait PipelineGoalSliceResolution
{
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

        foreach (array_reverse(PipelineGoalDecompositionService::PLANNING_LEVELS) as $level) {
            $atLevel = $overlapping->where('planning_level', $level)->values();
            if ($atLevel->isNotEmpty()) {
                return $atLevel->all();
            }
        }

        return [];
    }

    /**
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
}
