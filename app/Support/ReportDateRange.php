<?php

namespace App\Support;

use Carbon\Carbon;
use Illuminate\Http\Exceptions\HttpResponseException;

class ReportDateRange
{
    /**
     * @return array{0: string, 1: string}
     */
    public static function fromRequest(?string $dateFrom, ?string $dateTo): array
    {
        $from = $dateFrom ?: now()->toDateString();
        $to = $dateTo ?: now()->toDateString();

        if (Carbon::parse($from)->gt(Carbon::parse($to))) {
            throw new HttpResponseException(response()->json([
                'message' => 'date_from must be on or before date_to',
            ], 422));
        }

        return [$from, $to];
    }
}
