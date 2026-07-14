<?php

declare(strict_types=1);

namespace App\Services\Pipeline\Concerns;

use Carbon\Carbon;

trait PipelineGoalPeriodSlicing
{
    /** @return list<array{start: Carbon, end: Carbon, label: string}> */
    protected function periodSlices(string $level, Carbon $start, Carbon $end): array
    {
        $slices = [];
        $cursor = $start->copy()->startOfDay();
        $bound = $end->copy()->endOfDay();

        while ($cursor->lte($bound)) {
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
            if ($sliceEnd->gt($bound)) {
                $sliceEnd = $bound->copy();
            }

            $label = match ($level) {
                'quarter' => 'Q'.$cursor->quarter.' '.$cursor->year,
                'month' => $cursor->format('M Y'),
                'week' => 'W'.$cursor->isoWeek().' '.$cursor->year,
                'day' => $cursor->toDateString(),
                default => (string) $cursor->year,
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
}
