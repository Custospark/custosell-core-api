<?php

namespace App\Http\Controllers\Api\Platform;

use App\Http\Controllers\Controller;
use App\Services\Platform\PlatformOverviewService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PlatformOverviewController extends Controller
{
    public function __construct(
        protected PlatformOverviewService $overviewService,
    ) {}

    public function summary(): JsonResponse
    {
        return response()->json(['data' => $this->overviewService->summary()]);
    }

    public function metrics(Request $request): JsonResponse
    {
        $days = min(30, max(7, (int) $request->query('days', 7)));

        return response()->json(['data' => $this->overviewService->metrics($days)]);
    }
}
