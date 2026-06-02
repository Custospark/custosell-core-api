<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\ProductRequest;
use App\Http\Resources\ProductCollection;
use App\Http\Resources\ProductResource;
use App\Services\Contracts\ProductServiceInterface;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ProductController extends Controller
{
    public function __construct(
        protected ProductServiceInterface $productService,
    ) {}

    public function index(Request $request): ProductCollection
    {
        $businessId = $request->user()->business_id;
        return new ProductCollection($this->productService->getAll($businessId));
    }

    public function show(int $id): ProductResource
    {
        $product = $this->productService->getById($id);
        if (!$product) {
            abort(404, 'Product not found');
        }
        return new ProductResource($product);
    }

    public function store(ProductRequest $request): JsonResponse
    {
        $businessId = $request->user()->business_id;
        $product = $this->productService->create($businessId, $request->validated());
        return response()->json(new ProductResource($product), 201);
    }

    public function update(ProductRequest $request, int $id): ProductResource
    {
        $product = $this->productService->update($id, $request->validated());
        return new ProductResource($product);
    }

    public function destroy(int $id): JsonResponse
    {
        $this->productService->delete($id);
        return response()->json(null, 204);
    }

    public function active(Request $request): ProductCollection
    {
        $businessId = $request->user()->business_id;
        return new ProductCollection($this->productService->getActive($businessId));
    }

    public function lowStock(Request $request): ProductCollection
    {
        $businessId = $request->user()->business_id;
        return new ProductCollection($this->productService->getLowStock($businessId));
    }
}
