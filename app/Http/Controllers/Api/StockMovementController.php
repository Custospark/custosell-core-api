<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StockMovementRequest;
use App\Http\Resources\StockMovementCollection;
use App\Http\Resources\StockMovementResource;
use App\Services\Contracts\StockMovementServiceInterface;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class StockMovementController extends Controller
{
    public function __construct(
        protected StockMovementServiceInterface $stockMovementService,
    ) {}

    public function index(Request $request): StockMovementCollection
    {
        $businessId = $request->user()->business_id;
        return new StockMovementCollection($this->stockMovementService->getAll($businessId));
    }

    public function show(int $id): StockMovementResource
    {
        $stockMovement = $this->stockMovementService->getById($id);
        if (!$stockMovement) {
            abort(404, 'Stock movement not found');
        }
        return new StockMovementResource($stockMovement);
    }

    public function store(StockMovementRequest $request): JsonResponse
    {
        $businessId = $request->user()->business_id;
        $data = $request->validated();
        $data['created_by'] = $request->user()->id;
        $stockMovement = $this->stockMovementService->create($businessId, $data);
        return response()->json(new StockMovementResource($stockMovement), 201);
    }

    public function update(StockMovementRequest $request, int $id): StockMovementResource
    {
        $stockMovement = $this->stockMovementService->update($id, $request->validated());
        return new StockMovementResource($stockMovement);
    }

    public function destroy(int $id): JsonResponse
    {
        $this->stockMovementService->delete($id);
        return response()->json(null, 204);
    }
}
