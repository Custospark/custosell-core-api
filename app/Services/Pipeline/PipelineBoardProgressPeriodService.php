<?php

declare(strict_types=1);

namespace App\Services\Pipeline;

use Carbon\Carbon;

class PipelineBoardProgressPeriodService
{
    /** @return array{0: Carbon, 1: Carbon} */
    public function resolvePeriod(string $periodType, ?string $from, ?string $to): array
    {
        $now = now();

        return match ($periodType) {
            'day' => [$now->copy()->startOfDay(), $now->copy()->endOfDay()],
            'week' => [$now->copy()->startOfWeek(), $now->copy()->endOfWeek()],
            'month' => [$now->copy()->startOfMonth(), $now->copy()->endOfMonth()],
            'quarter' => [$now->copy()->firstOfQuarter(), $now->copy()->lastOfQuarter()->endOfDay()],
            'year' => [$now->copy()->startOfYear(), $now->copy()->endOfYear()],
            'custom' => [
                Carbon::parse($from ?? $now->copy()->startOfMonth()->toDateString())->startOfDay(),
                Carbon::parse($to ?? $now->toDateString())->endOfDay(),
            ],
            default => [$now->copy()->startOfMonth(), $now->copy()->endOfMonth()],
        };
    }
}
