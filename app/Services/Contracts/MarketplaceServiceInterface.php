<?php

namespace App\Services\Contracts;

use Illuminate\Database\Eloquent\Collection;

interface MarketplaceServiceInterface
{
    /**
     * Businesses opted in to selling on the supply marketplace.
     *
     * @return Collection<int, \App\Models\Business>
     */
    public function listBusinessesOpenForSupply(int $excludeBusinessId, ?string $q = null): Collection;

    /**
     * A seller's opted-in, active, stocked products visible to buyers.
     *
     * @return Collection<int, \App\Models\Product>
     */
    public function listListedProducts(int $sellerBusinessId): Collection;
}
