<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\MarketplaceBusinessResource;
use App\Http\Resources\ProductCollection;
use App\Services\Contracts\MarketplaceServiceInterface;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class MarketplaceController extends Controller
{
    public function __construct(
        protected MarketplaceServiceInterface $marketplaceService,
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
}
