<?php

namespace App\Http\Controllers\Api\Platform;

use App\Http\Controllers\Controller;
use App\Models\Business;
use App\Services\Platform\PlatformBusinessService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PlatformBusinessController extends Controller
{
    public function __construct(
        protected PlatformBusinessService $businessService,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $paginator = $this->businessService->paginate([
            'search' => $request->query('search'),
            'status' => $request->query('status'),
            'currency' => $request->query('currency'),
            'activity_status' => $request->query('activity_status'),
            'sort' => $request->query('sort', 'revenue_30d'),
            'direction' => $request->query('direction', 'desc'),
        ], (int) $request->query('per_page', 15));

        return response()->json($paginator);
    }

    public function updateStatus(Request $request, int $id): JsonResponse
    {
        $validated = $request->validate([
            'status' => ['required', 'in:active,suspended'],
            'reason' => ['nullable', 'string', 'max:1000'],
        ]);

        $business = Business::with('owner')->findOrFail($id);
        $updated = $this->businessService->updateStatus(
            $request->user(),
            $business,
            $validated['status'],
            $validated['reason'] ?? null,
        );

        return response()->json([
            'data' => $this->businessService->transformBusiness($updated),
            'message' => $validated['status'] === 'suspended' ? 'Business suspended.' : 'Business reactivated.',
        ]);
    }
}
