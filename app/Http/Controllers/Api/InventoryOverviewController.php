<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\InventoryOverviewService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class InventoryOverviewController extends Controller
{
    public function __construct(private InventoryOverviewService $overview) {}

    public function show(Request $request): JsonResponse
    {
        $businessId = (int) $request->user()->business_id;
        $locationId = $request->filled('location_id') ? (int) $request->query('location_id') : null;

        return response()->json($this->overview->overview($businessId, $locationId));
    }
}