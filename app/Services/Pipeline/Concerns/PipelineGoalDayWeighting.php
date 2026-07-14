<?php

declare(strict_types=1);

namespace App\Services\Pipeline\Concerns;

use Carbon\Carbon;

trait PipelineGoalDayWeighting
{
    /** Inclusive calendar-day count between two dates (start/end normalized to startOfDay). */
    public function inclusiveDays(Carbon $start, Carbon $end): int
    {
        $from = $start->copy()->startOfDay();
        $to = $end->copy()->startOfDay();

        if ($to->lt($from)) {
            return 0;
        }

        return (int) ($from->diffInDays($to) + 1);
    }

    /**
     * Day-weighted slice share of a parent period:
     * days(slice ∩ parent) / days(parent).
     */
    public function dayWeightedShare(
        Carbon $sliceStart,
        Carbon $sliceEnd,
        Carbon $parentStart,
        Carbon $parentEnd,
        ?int $parentDays = null,
    ): float {
        $parentFrom = $parentStart->copy()->startOfDay();
        $parentTo = $parentEnd->copy()->startOfDay();
        $overlapStart = $sliceStart->copy()->startOfDay()->max($parentFrom);
        $overlapEnd = $sliceEnd->copy()->startOfDay()->min($parentTo);

        if ($overlapStart->gt($overlapEnd)) {
            return 0.0;
        }

        $daysParent = $parentDays ?? max(1, $this->inclusiveDays($parentFrom, $parentTo));

        return $this->inclusiveDays($overlapStart, $overlapEnd) / $daysParent;
    }

    /**
     * Cumulative expected through a period end within the horizon:
     * T × days(horizon_start → clip(period_end, horizon_end)) / days_horizon.
     */
    public function cumulativeExpectedThrough(
        float $targetValue,
        Carbon $horizonStart,
        Carbon $horizonEnd,
        Carbon $periodEnd,
        ?int $horizonDays = null,
    ): float {
        if ($targetValue <= 0) {
            return 0.0;
        }

        $hStart = $horizonStart->copy()->startOfDay();
        $hEnd = $horizonEnd->copy()->startOfDay();
        $clippedEnd = $periodEnd->copy()->startOfDay()->min($hEnd);

        if ($clippedEnd->lt($hStart)) {
            return 0.0;
        }

        $daysHorizon = $horizonDays ?? max(1, $this->inclusiveDays($hStart, $hEnd));
        $elapsed = $this->inclusiveDays($hStart, $clippedEnd);

        return round($targetValue * ($elapsed / $daysHorizon), 4);
    }
}
