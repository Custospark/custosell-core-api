<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Hr;

use App\Http\Controllers\Controller;
use App\Http\Requests\AssignFixedAssetRequest;
use App\Http\Requests\ReturnFixedAssetRequest;
use App\Http\Requests\StoreFixedAssetRequest;
use App\Http\Requests\TransferFixedAssetRequest;
use App\Http\Requests\UpdateCustodyFixedAssetRequest;
use App\Http\Resources\ExpenseResource;
use App\Http\Resources\FixedAssetAssignmentResource;
use App\Http\Resources\FixedAssetCollection;
use App\Http\Resources\FixedAssetResource;
use App\Services\FixedAssetService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class HrCompanyAssetsController extends Controller
{
    public function __construct(
        protected FixedAssetService $fixedAssetService,
    ) {}

    public function index(Request $request): FixedAssetCollection
    {
        $businessId = (int) $request->user()->business_id;
        $filters = $request->only([
            'status',
            'search',
            'q',
            'category',
            'assigned_employee_id',
            'unassigned',
            'per_page',
        ]);
        if (!empty($filters['q']) && empty($filters['search'])) {
            $filters['search'] = $filters['q'];
        }
        unset($filters['q']);

        return new FixedAssetCollection(
            $this->fixedAssetService->getAll($businessId, $filters)
        );
    }

    public function show(Request $request, int $id): FixedAssetResource
    {
        $businessId = (int) $request->user()->business_id;

        return new FixedAssetResource(
            $this->fixedAssetService->getByIdForBusiness($id, $businessId)
        );
    }

    public function store(StoreFixedAssetRequest $request): JsonResponse
    {
        $businessId = (int) $request->user()->business_id;
        $asset = $this->fixedAssetService->create($businessId, $request->validated());

        return (new FixedAssetResource($asset))
            ->response()
            ->setStatusCode(201);
    }

    public function updateCustody(UpdateCustodyFixedAssetRequest $request, int $id): FixedAssetResource
    {
        $businessId = (int) $request->user()->business_id;
        $asset = $this->fixedAssetService->updateCustody($id, $request->validated(), $businessId);

        return new FixedAssetResource($asset);
    }

    public function assign(AssignFixedAssetRequest $request, int $id): FixedAssetResource
    {
        $businessId = (int) $request->user()->business_id;
        $validated = $request->validated();

        $asset = $this->fixedAssetService->assign(
            $id,
            (int) $validated['employee_id'],
            (int) $request->user()->id,
            $validated['notes'] ?? null,
            $businessId,
        );

        return new FixedAssetResource($asset);
    }

    public function transfer(TransferFixedAssetRequest $request, int $id): FixedAssetResource
    {
        $businessId = (int) $request->user()->business_id;
        $validated = $request->validated();

        $asset = $this->fixedAssetService->transfer(
            $id,
            (int) $validated['employee_id'],
            (int) $request->user()->id,
            $validated['notes'] ?? null,
            $businessId,
        );

        return new FixedAssetResource($asset);
    }

    public function returnAsset(ReturnFixedAssetRequest $request, int $id): FixedAssetResource
    {
        $businessId = (int) $request->user()->business_id;
        $validated = $request->validated();

        $asset = $this->fixedAssetService->returnAsset(
            $id,
            (int) $request->user()->id,
            $validated['notes'] ?? null,
            $businessId,
        );

        return new FixedAssetResource($asset);
    }

    public function assignments(Request $request, int $id): JsonResponse
    {
        $businessId = (int) $request->user()->business_id;
        $assignments = $this->fixedAssetService->getAssignments($id, $businessId);

        return response()->json([
            'data' => FixedAssetAssignmentResource::collection($assignments),
        ]);
    }

    public function maintenanceExpenses(Request $request, int $id): JsonResponse
    {
        $businessId = (int) $request->user()->business_id;
        $expenses = $this->fixedAssetService->getMaintenanceExpenses($id, $businessId);

        return response()->json([
            'data' => ExpenseResource::collection($expenses),
        ]);
    }
}
