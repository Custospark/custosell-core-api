<?php

namespace App\Http\Controllers\Api\Platform;

use App\Http\Controllers\Controller;
use App\Models\Business;
use App\Services\Platform\PlatformBusinessService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PlatformBusinessController extends Controller
{
    public function __construct(
        protected PlatformBusinessService $businessService,
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
            'data' => $this->businessService->onboardingDashboard($rangeFrom, $rangeTo),
        ]);
    }

    public function index(Request $request): JsonResponse
    {
        $perPage = min(500, max(15, (int) $request->query('per_page', 50)));

        $paginator = $this->businessService->paginate([
            'search' => $request->query('search'),
            'status' => $request->query('status'),
            'currency' => $request->query('currency'),
            'activity_status' => $request->query('activity_status'),
            'sort' => $request->query('sort', 'gross_sales_30d'),
            'direction' => $request->query('direction', 'desc'),
        ], $perPage);

        return response()->json($paginator);
    }

    public function updateStatus(Request $request, int $id): JsonResponse
    {
        $validated = $request->validate([
            'status' => ['required', 'in:active,suspended'],
            'reason' => ['required', 'string', 'min:3', 'max:1000'],
        ]);

        $business = Business::with('owner')->findOrFail($id);
        $updated = $this->businessService->updateStatus(
            $request->user(),
            $business,
            $validated['status'],
            $validated['reason'],
        );

        return response()->json([
            'data' => $this->businessService->transformBusiness($updated),
            'message' => $validated['status'] === 'suspended' ? 'Business suspended.' : 'Business reactivated.',
        ]);
    }
}
