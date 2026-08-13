<?php

namespace App\Http\Controllers\Api\Platform;

use App\Http\Controllers\Controller;
use App\Services\Platform\PlatformConversionMetricsService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PlatformConversionController extends Controller
{
    public function __construct(
        protected PlatformConversionMetricsService $metrics,
    ) {}

    public function stats(Request $request): JsonResponse
    {
        $rangeFrom = $request->query('date_from')
            ? Carbon::parse($request->query('date_from'))->startOfDay()
            : null;
        $rangeTo = $request->query('date_to')
            ? Carbon::parse($request->query('date_to'))->endOfDay()
            : null;

        return response()->json([
            'data' => $this->metrics->conversionDashboard($rangeFrom, $rangeTo),
        ]);
    }
}