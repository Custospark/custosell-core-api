<?php

declare(strict_types=1);

namespace App\Services\Storefront;

use App\Models\Product;
use App\Models\ProductWishlist;
use App\Support\StorefrontSlug;
use Illuminate\Support\Collection;

class WishlistService
{
    /**
     * List all wishlist items for a given buyer, with eager-loaded product + shop data.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function list(int $userId): Collection
    {
        $items = ProductWishlist::query()
            ->where('user_id', $userId)
            ->with([
                'product' => function ($q) {
                    $q->with([
                        'category:id,name',
                        'business:id,name,slug,logo_path,city,currency',
                    ]);
                },
            ])
            ->orderByDesc('id')
            ->get();

        return $items->map(fn (ProductWishlist $w) => $this->payload($w));
    }

    /**
     * Add a product to the buyer's wishlist (auto-deduplicates).
     *
     * @return array<string, mixed>
     */
    public function add(int $userId, int $productId): array
    {
        $wish = ProductWishlist::query()->firstOrCreate(
            ['user_id' => $userId, 'product_id' => $productId],
        );

        $wish->load([
            'product' => function ($q) {
                $q->with([
                    'category:id,name',
                    'business:id,name,slug,logo_path,city,currency',
                ]);
            },
        ]);

        return $this->payload($wish);
    }

    /**
     * Remove a specific wishlist item. Returns true if deleted, false if not found.
     */
    public function remove(int $userId, int $wishlistId): bool
    {
        $deleted = ProductWishlist::query()
            ->where('id', $wishlistId)
            ->where('user_id', $userId)
            ->delete();

        return $deleted > 0;
    }

    /**
     * Remove a product from wishlist by product_id (for heart toggle from card).
     */
    public function removeByProduct(int $userId, int $productId): bool
    {
        $deleted = ProductWishlist::query()
            ->where('user_id', $userId)
            ->where('product_id', $productId)
            ->delete();

        return $deleted > 0;
    }

    /**
     * Drop wishlist rows for ordered products (place-order cleanup).
     *
     * @param  list<int>  $productIds
     */
    public function removeProducts(int $userId, array $productIds): int
    {
        $ids = array_values(array_unique(array_filter(
            array_map('intval', $productIds),
            fn (int $id) => $id > 0,
        )));

        if ($ids === []) {
            return 0;
        }

        return (int) ProductWishlist::query()
            ->where('user_id', $userId)
            ->whereIn('product_id', $ids)
            ->delete();
    }

    /**
     * Check if a product is in the user's wishlist.
     */
    public function isWishlisted(int $userId, int $productId): bool
    {
        return ProductWishlist::query()
            ->where('user_id', $userId)
            ->where('product_id', $productId)
            ->exists();
    }

    /**
     * Get wishlist IDs keyed by product_id for bulk lookup.
     *
     * @return array<int, int> product_id => wishlist_id
     */
    public function wishlistIdsByProduct(int $userId): array
    {
        return ProductWishlist::query()
            ->where('user_id', $userId)
            ->pluck('id', 'product_id')
            ->all();
    }

    /**
     * Count wishlist items for a user.
     */
    public function count(int $userId): int
    {
        return ProductWishlist::query()
            ->where('user_id', $userId)
            ->count();
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(ProductWishlist $wish): array
    {
        $product = $wish->product;

        $business = $product?->business;

        $onSale = $product?->hasDiscount() ?? false;
        $salePrice = $onSale && $product ? $product->effectiveUnitPrice() : null;

        return [
            'id' => $wish->id,
            'product_id' => $wish->product_id,
            'created_at' => $wish->created_at?->toISOString(),
            'product' => $product ? [
                'id' => $product->id,
                'name' => $product->name,
                'description' => $product->description,
                'unit_price' => $product->unit_price,
                'discount_percent' => $onSale ? (float) $product->discount_percent : null,
                'sale_price' => $salePrice,
                'compare_at_price' => $onSale ? (float) $product->unit_price : null,
                'unit' => $product->unit,
                'image_path' => $product->image_path,
                'type' => $product->type ?? 'product',
                'stock_quantity' => (int) ($product->stock_quantity ?? 0),
                'in_stock' => $product->isService() || (int) ($product->stock_quantity ?? 0) > 0,
                'availability' => $product->isService()
                    ? 'always'
                    : ((int) ($product->stock_quantity ?? 0) > 0 ? 'in_stock' : 'out'),
                'category' => $product->relationLoaded('category') && $product->category
                    ? ['id' => $product->category->id, 'name' => $product->category->name]
                    : null,
                'business' => $business ? [
                    'name' => $business->name,
                    'slug' => $business->slug,
                    'logo_path' => $business->logo_path,
                    'city' => $business->city,
                    'currency' => $business->currency ?? 'UGX',
                ] : null,
            ] : null,
        ];
    }
}
