<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\RatioService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class RatioController extends Controller
{
    public function __construct(
        protected RatioService $ratioService,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $businessId = $request->user()->business_id;
        $periodId = $request->query('period_id');

        if (!$periodId) {
            return response()->json(['message' => 'period_id is required'], 422);
        }

        return response()->json([
            'data' => $this->ratioService->calculateAll($businessId, (int) $periodId),
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
