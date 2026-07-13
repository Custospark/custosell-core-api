<?php

declare(strict_types=1);

namespace App\Services\Storefront;

use App\Models\Product;
use Illuminate\Database\Eloquent\Builder;

/** Shared public catalog payload + rating eager-loads for storefront listings. */
trait StorefrontCatalogConcern
{
    /** @return array<string, mixed> */
    public function publicProductPayload(Product $product, ?int $viewerUserId = null): array
    {
        $avg = $product->storefront_ratings_avg_rating;
        $count = (int) ($product->storefront_ratings_count ?? 0);
        $my = null;
        if ($viewerUserId && $product->relationLoaded('myStorefrontRating')) {
            $my = $product->myStorefrontRating?->rating;
        }

        $type = $product->type ?? Product::TYPE_PRODUCT;
        $stockQuantity = (int) ($product->stock_quantity ?? 0);
        $isService = $type === Product::TYPE_SERVICE;
        $inStock = $isService || $stockQuantity > 0;
        $availability = $isService ? 'always' : ($inStock ? 'in_stock' : 'out');

        $onSale = $product->hasDiscount();
        $salePrice = $onSale ? $product->effectiveUnitPrice() : null;

        return [
            'id' => $product->id,
            'name' => $product->name,
            'description' => $product->description,
            'unit_price' => $product->unit_price,
            'discount_percent' => $onSale ? (float) $product->discount_percent : null,
            'sale_price' => $salePrice,
            'compare_at_price' => $onSale ? (float) $product->unit_price : null,
            'unit' => $product->unit,
            'image_path' => $product->image_path,
            'type' => $type,
            'stock_quantity' => $stockQuantity,
            'in_stock' => $inStock,
            'availability' => $availability,
            'rating_avg' => $count > 0 ? round((float) $avg, 1) : 0,
            'rating_count' => $count,
            'my_rating' => $my !== null ? (int) $my : null,
            'category' => $product->relationLoaded('category') && $product->category
                ? ['id' => $product->category->id, 'name' => $product->category->name]
                : null,
            'business' => $product->relationLoaded('business') && $product->business
                ? [
                    'name' => $product->business->name,
                    'slug' => $product->business->slug,
                    'logo_path' => $product->business->logo_path,
                    'city' => $product->business->city,
                    'currency' => $product->business->currency ?? 'UGX',
                ]
                : null,
        ];
    }

    private function withProductStorefrontRatingAggregates(Builder $query, ?int $viewerUserId): void
    {
        $query
            ->withAvg('storefrontRatings', 'rating')
            ->withCount('storefrontRatings');

        if ($viewerUserId !== null && $viewerUserId > 0) {
            $query->with([
                'myStorefrontRating' => fn ($q) => $q->where('user_id', $viewerUserId),
            ]);
        }
    }

    private function withShopStorefrontRatingAggregates(Builder $query, ?int $viewerUserId): void
    {
        $query
            ->withAvg('storefrontRatings', 'rating')
            ->withCount('storefrontRatings');

        if ($viewerUserId !== null && $viewerUserId > 0) {
            $query->with([
                'myStorefrontRating' => fn ($q) => $q->where('user_id', $viewerUserId),
            ]);
        }
    }

    private function listedProductsQuery(): Builder
    {
        return Product::query()
            ->where('products.listed_for_storefront', true)
            ->where('products.is_active', true)
            ->whereHas('business', fn (Builder $b) => $b->publicStorefront());
    }
}
