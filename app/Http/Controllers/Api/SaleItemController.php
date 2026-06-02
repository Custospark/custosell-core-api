<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\SaleItemRequest;
use App\Http\Resources\SaleItemCollection;
use App\Http\Resources\SaleItemResource;
use App\Services\Contracts\SaleItemServiceInterface;
use Illuminate\Http\JsonResponse;

class SaleItemController extends Controller
{
    public function __construct(
        protected SaleItemServiceInterface $saleItemService,
    ) {}

    public function index(): SaleItemCollection
    {
        return new SaleItemCollection($this->saleItemService->getAll());
    }

    public function show(int $id): SaleItemResource
    {
        $saleItem = $this->saleItemService->getById($id);
        if (!$saleItem) {
            abort(404, 'Sale item not found');
        }
        return new SaleItemResource($saleItem);
    }

    public function store(SaleItemRequest $request): JsonResponse
    {
        $saleItem = $this->saleItemService->create($request->validated());
        return response()->json(new SaleItemResource($saleItem), 201);
    }

    public function update(SaleItemRequest $request, int $id): SaleItemResource
    {
        $saleItem = $this->saleItemService->update($id, $request->validated());
        return new SaleItemResource($saleItem);
    }

    public function destroy(int $id): JsonResponse
    {
        $this->saleItemService->delete($id);
        return response()->json(null, 204);
    }
}
