<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\MarketplaceBusinessResource;
use App\Http\Resources\ProductCollection;
use App\Services\Contracts\MarketplaceServiceInterface;
use App\Services\Contracts\SupplierListServiceInterface;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class MarketplaceController extends Controller
{
    public function __construct(
        protected MarketplaceServiceInterface $marketplaceService,
        protected SupplierListServiceInterface $supplierListService,
    ) {}

    public function businesses(Request $request): AnonymousResourceCollection
    {
        $businessId = $request->user()->business_id;
        $q = $request->query('q');

        return MarketplaceBusinessResource::collection(
            $this->marketplaceService->listBusinessesOpenForSupply($businessId, $q)
        );
    }

    public function products(int $businessId): ProductCollection
    {
        return new ProductCollection($this->marketplaceService->listListedProducts($businessId));
    }

    public function supplierList(Request $request): AnonymousResourceCollection
    {
        $businessId = $request->user()->business_id;
        $q = $request->query('q');

        return MarketplaceBusinessResource::collection(
            $this->supplierListService->listForBuyer($businessId, is_string($q) ? $q : null)
        );
    }

    public function addSupplier(Request $request): JsonResponse
    {
        $data = $request->validate([
            'seller_business_id' => ['required', 'integer', 'exists:businesses,id'],
            'notes' => ['nullable', 'string', 'max:500'],
        ]);

        $business = $this->supplierListService->add(
            $request->user()->business_id,
            (int) $data['seller_business_id'],
            $data['notes'] ?? null,
        );

        return (new MarketplaceBusinessResource($business))
            ->response()
            ->setStatusCode(201);
    }

    public function removeSupplier(Request $request, int $sellerBusinessId): JsonResponse
    {
        $this->supplierListService->remove($request->user()->business_id, $sellerBusinessId);

        return response()->json(null, 204);
    }
}
