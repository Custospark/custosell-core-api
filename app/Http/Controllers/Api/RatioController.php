<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\RatioService;
use App\Services\ReportPeriodResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class RatioController extends Controller
{
    public function __construct(
        protected RatioService $ratioService,
        protected ReportPeriodResolver $reportPeriodResolver,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $businessId = $request->user()->business_id;
        $ctx = $this->reportPeriodResolver->resolve($businessId, $request);

        return response()->json([
            'data' => $this->ratioService->calculateAllForContext($businessId, $ctx),
        ]);
    }

    public function trends(Request $request): JsonResponse
    {
        $businessId = $request->user()->business_id;
        $interval = $request->query('interval', 'monthly');
        $count = (int) $request->query('count', 12);

        return response()->json([
            'data' => $this->ratioService->getTrends($businessId, $interval, $count),
        ]);
    }
}
