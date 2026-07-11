<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreFixedAssetRequest;
use App\Http\Resources\DepreciationEntryResource;
use App\Http\Resources\FixedAssetCollection;
use App\Http\Resources\FixedAssetResource;
use App\Services\DepreciationService;
use App\Services\FixedAssetService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class FixedAssetController extends Controller
{
    public function __construct(
        protected FixedAssetService $fixedAssetService,
        protected DepreciationService $depreciationService,
    ) {}

    public function index(Request $request): FixedAssetCollection
    {
        $businessId = $request->user()->business_id;
        $filters = $request->only(['status', 'search', 'category', 'assigned_employee_id', 'unassigned']);
        return new FixedAssetCollection(
            $this->fixedAssetService->getAll($businessId, $filters)
        );
    }

    public function show(int $id): FixedAssetResource
    {
        return new FixedAssetResource(
            $this->fixedAssetService->getById($id)
        );
    }

    public function store(StoreFixedAssetRequest $request): JsonResponse
    {
        $businessId = $request->user()->business_id;
        $asset = $this->fixedAssetService->create($businessId, $request->validated());
        return (new FixedAssetResource($asset))
            ->response()
            ->setStatusCode(201);
    }

    public function update(StoreFixedAssetRequest $request, int $id): FixedAssetResource
    {
        $businessId = (int) $request->user()->business_id;
        $data = $request->validated();

        if (($data['status'] ?? null) === 'disposed') {
            $asset = $this->fixedAssetService->getByIdForBusiness($id, $businessId);
            if ($asset->assigned_employee_id) {
                throw ValidationException::withMessages([
                    'status' => 'Cannot dispose an asset that is still assigned. Return it first.',
                ]);
            }
        }

        return new FixedAssetResource(
            $this->fixedAssetService->update($id, $data, $businessId)
        );
    }

    public function destroy(int $id): JsonResponse
    {
        $this->fixedAssetService->delete($id);
        return response()->json(null, 204);
    }

    public function runDepreciation(Request $request): JsonResponse
    {
        $businessId = $request->user()->business_id;
        $userId = $request->user()->id;
        $periodId = $request->input('period_id');

        if (!$periodId) {
            return response()->json(['message' => 'period_id is required'], 422);
        }

        $results = $this->depreciationService->runDepreciation($businessId, (int) $periodId, $userId);
        return response()->json(['data' => $results]);
    }

    public function schedule(int $id): JsonResponse
    {
        return response()->json([
            'data' => DepreciationEntryResource::collection(
                $this->depreciationService->getSchedule($id)
            ),
        ]);
    }
}
