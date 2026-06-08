<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\ReportMetricsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
  public function __construct(private ReportMetricsService $metrics) {}

  public function summary(Request $request): JsonResponse
  {
    $businessId = (int) $request->user()->business_id;

    return response()->json($this->metrics->dashboardSummary($businessId));
  }
}
