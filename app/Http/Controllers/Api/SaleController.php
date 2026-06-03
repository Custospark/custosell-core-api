<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\SaleRequest;
use App\Http\Resources\SaleCollection;
use App\Http\Resources\SaleResource;
use App\Services\Contracts\SaleServiceInterface;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SaleController extends Controller
{
    public function __construct(
        protected SaleServiceInterface $saleService,
    ) {}

    public function index(Request $request): SaleCollection
    {
        $businessId = $request->user()->business_id;
        return new SaleCollection($this->saleService->getAll($businessId));
    }

    public function show(int $id): SaleResource
    {
        $sale = $this->saleService->getById($id);
        if (!$sale) {
            abort(404, 'Sale not found');
        }
        return new SaleResource($sale);
    }

    public function store(SaleRequest $request): JsonResponse
    {
        $businessId = $request->user()->business_id;
        $userId = $request->user()->id;
        $sale = $this->saleService->create($businessId, $userId, $request->validated());
        return response()->json(new SaleResource($sale), 201);
    }

    public function update(SaleRequest $request, int $id): SaleResource
    {
        $sale = $this->saleService->update($id, $request->validated());
        return new SaleResource($sale);
    }

    public function destroy(int $id): JsonResponse
    {
        $this->saleService->delete($id);
        return response()->json(null, 204);
    }

    public function daily(Request $request): SaleCollection
    {
        $businessId = $request->user()->business_id;
        $date = $request->query('date');
        return new SaleCollection($this->saleService->getDaily($businessId, $date));
    }

    public function refund(int $id, Request $request): SaleResource
    {
        $sale = $this->saleService->refund($id, $request->validate([
            'items' => ['required', 'array', 'min:1'],
            'items.*.id' => ['required', 'integer', 'exists:sale_items,id'],
            'items.*.quantity' => ['required', 'integer', 'min:1'],
            'items.*.amount' => ['nullable', 'numeric', 'min:0'],
        ]));
        return new SaleResource($sale);
    }
}
