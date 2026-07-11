<?php

namespace App\Services;

use App\Models\Business;
use App\Models\Product;
use App\Services\Contracts\MarketplaceServiceInterface;
use Illuminate\Database\Eloquent\Collection;

class MarketplaceService implements MarketplaceServiceInterface
{
    public function listBusinessesOpenForSupply(int $excludeBusinessId, ?string $q = null): Collection
    {
        $query = Business::query()
            ->where('is_open_for_supply', true)
            ->where('id', '!=', $excludeBusinessId)
            ->orderBy('name');

        if ($q !== null && trim($q) !== '') {
            $term = trim($q);
            $query->where(function ($builder) use ($term) {
                $builder->where('name', 'like', "%{$term}%")
                    ->orWhere('supply_headline', 'like', "%{$term}%");
            });
        }

        return $query->get();
    }

    public function listListedProducts(int $sellerBusinessId): Collection
    {
        $seller = Business::find($sellerBusinessId);
        if (! $seller || ! $seller->isOpenForSupply()) {
            return new Collection;
        }

        return Product::query()
            ->where('business_id', $sellerBusinessId)
            ->where('type', Product::TYPE_PRODUCT)
            ->where('is_active', true)
            ->where('listed_for_supply', true)
            ->orderBy('name')
            ->get();
    }
}
