<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreChartOfAccountRequest;
use App\Http\Resources\ChartOfAccountCollection;
use App\Http\Resources\ChartOfAccountResource;
use App\Services\ChartOfAccountService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ChartOfAccountController extends Controller
{
    public function __construct(
        protected ChartOfAccountService $chartOfAccountService,
    ) {}

    public function index(Request $request): ChartOfAccountCollection
    {
        $businessId = $request->user()->business_id;
        $filters = $request->only(['type_id', 'is_active', 'search']);
        return new ChartOfAccountCollection(
            $this->chartOfAccountService->getAll($businessId, $filters)
        );
    }

    public function tree(Request $request): JsonResponse
    {
        $businessId = $request->user()->business_id;
        return response()->json([
            'data' => $this->chartOfAccountService->getTree($businessId),
        ]);
    }

    public function show(Request $request, int $id): ChartOfAccountResource
    {
        return new ChartOfAccountResource(
            $this->chartOfAccountService->getById($id)
        );
    }

    public function store(StoreChartOfAccountRequest $request): JsonResponse
    {
        $businessId = $request->user()->business_id;
        $account = $this->chartOfAccountService->create($businessId, $request->validated());
        return response()->json(new ChartOfAccountResource($account), 201);
    }

    public function update(StoreChartOfAccountRequest $request, int $id): ChartOfAccountResource
    {
        return new ChartOfAccountResource(
            $this->chartOfAccountService->update($id, $request->validated())
        );
    }

    public function destroy(int $id): JsonResponse
    {
        $this->chartOfAccountService->deactivate($id);
        return response()->json(null, 204);
    }
}
