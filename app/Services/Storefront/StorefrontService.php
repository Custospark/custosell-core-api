<?php

declare(strict_types=1);

namespace App\Services\Storefront;

use App\Models\Business;
use App\Models\BusinessStorefrontRating;
use App\Models\Category;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductStorefrontRating;
use App\Services\Contracts\OrderServiceInterface;
use App\Support\StorefrontSlug;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

class StorefrontService
{
    public function __construct(
        private readonly OrderServiceInterface $orderService,
    ) {}

    public function findEnabledShop(string $slug): Business
    {
        $normalized = StorefrontSlug::normalize($slug);
        $business = Business::query()
            ->where('slug', $normalized)
            ->where('storefront_enabled', true)
            ->where('status', 'active')
            ->first();

        if (!$business) {
            abort(404, 'Shop not found');
        }

        return $business;
    }

    /** @return array<string, mixed> */
    public function publicShopPayload(Business $business, ?int $viewerUserId = null): array
    {
        $avg = $business->storefront_ratings_avg_rating;
        $count = (int) ($business->storefront_ratings_count ?? 0);
        $my = null;
        if ($viewerUserId && $business->relationLoaded('myStorefrontRating')) {
            $my = $business->myStorefrontRating?->rating;
        }

        return [
            'name' => $business->name,
            'slug' => $business->slug,
            'description' => $business->description,
            'logo_path' => $business->logo_path,
            'city' => $business->city,
            'country' => $business->country,
            'address' => $business->address,
            'state' => $business->state,
            'business_phone' => $business->business_phone ?? $business->phone,
            'business_email' => $business->business_email ?? $business->email,
            'currency' => $business->currency ?? 'UGX',
            'rating_avg' => $count > 0 ? round((float) $avg, 1) : 0,
            'rating_count' => $count,
            'my_rating' => $my !== null ? (int) $my : null,
        ];
    }

    public function discoverShops(?string $q, int $perPage = 24, ?int $viewerUserId = null): LengthAwarePaginator
    {
        $query = Business::query()
            ->where('storefront_enabled', true)
            ->where('status', 'active');

        if ($q !== null && trim($q) !== '') {
            // Discover invites "@username" — strip so slug LIKE matches.
            $normalized = ltrim(trim($q), '@');
            $term = '%'.$normalized.'%';
            $slugTerm = '%'.StorefrontSlug::normalize($normalized).'%';
            $query->where(function (Builder $b) use ($term, $slugTerm) {
                $b->where('name', 'like', $term)
                    ->orWhere('city', 'like', $term)
                    ->orWhere('description', 'like', $term)
                    ->orWhere('slug', 'like', $term)
                    ->orWhere('slug', 'like', $slugTerm);
            });
        }

        $this->withShopStorefrontRatingAggregates($query, $viewerUserId);

        return $query
            ->orderBy('name')
            ->paginate(min(48, max(1, $perPage)));
    }

    public function discoverProducts(?string $q, ?string $category, int $perPage = 24, ?int $viewerUserId = null): LengthAwarePaginator
    {
        $query = $this->listedProductsQuery();

        if ($q !== null && trim($q) !== '') {
            $term = '%'.trim($q).'%';
            $query->where(function (Builder $b) use ($term) {
                $b->where('products.name', 'like', $term)
                    ->orWhere('products.description', 'like', $term)
                    ->orWhereHas('business', fn (Builder $bb) => $bb->where('name', 'like', $term));
            });
        }

        if ($category !== null && trim($category) !== '') {
            $cat = trim($category);
            $query->whereHas('category', function (Builder $b) use ($cat) {
                if (ctype_digit($cat)) {
                    $b->where('categories.id', (int) $cat);
                } else {
                    $b->where('categories.name', 'like', '%'.$cat.'%');
                }
            });
        }

        $this->withProductStorefrontRatingAggregates($query, $viewerUserId);

        return $query
            ->with(['category:id,name', 'business:id,name,slug,logo_path,city,currency,storefront_enabled'])
            ->orderByDesc('products.storefront_listed_at')
            ->orderByDesc('products.id')
            ->paginate(min(48, max(1, $perPage)));
    }

    /** @return Collection<int, array{id: int|null, name: string, product_count: int}> */
    public function discoverCategories(): Collection
    {
        return Category::query()
            ->select('categories.id', 'categories.name')
            ->selectRaw('COUNT(products.id) as product_count')
            ->join('products', 'products.category_id', '=', 'categories.id')
            ->join('businesses', 'businesses.id', '=', 'products.business_id')
            ->where('businesses.storefront_enabled', true)
            ->where('businesses.status', 'active')
            ->whereNull('businesses.deleted_at')
            ->where('products.listed_for_storefront', true)
            ->where('products.is_active', true)
            ->whereNull('products.deleted_at')
            ->groupBy('categories.id', 'categories.name')
            ->orderBy('categories.name')
            ->get()
            ->map(fn (Category $c) => [
                'id' => $c->id,
                'name' => $c->name,
                'product_count' => (int) $c->product_count,
            ]);
    }

    public function shopProducts(Business $business, ?string $category = null, ?int $viewerUserId = null): Collection
    {
        $query = Product::query()
            ->where('business_id', $business->id)
            ->where('listed_for_storefront', true)
            ->where('is_active', true)
            ->with(['category:id,name']);

        if ($category !== null && trim($category) !== '') {
            $cat = trim($category);
            $query->whereHas('category', function (Builder $b) use ($cat) {
                if (ctype_digit($cat)) {
                    $b->where('categories.id', (int) $cat);
                } else {
                    $b->where('categories.name', 'like', '%'.$cat.'%');
                }
            });
        }

        $this->withProductStorefrontRatingAggregates($query, $viewerUserId);

        return $query->orderBy('name')->get();
    }

    /**
     * Upsert a 1–5 star rating for a listed storefront product (Sanctum buyer).
     *
     * @return array<string, mixed>
     */
    public function rateProduct(string $slug, int $productId, int $userId, int $rating): array
    {
        if ($rating < 1 || $rating > 5) {
            throw ValidationException::withMessages([
                'rating' => ['Choose a rating from 1 to 5 stars.'],
            ]);
        }

        $business = $this->findEnabledShop($slug);
        $product = Product::query()
            ->where('business_id', $business->id)
            ->where('id', $productId)
            ->where('listed_for_storefront', true)
            ->where('is_active', true)
            ->first();

        if (!$product) {
            throw ValidationException::withMessages([
                'product_id' => ['This product is not available in the shop.'],
            ]);
        }

        ProductStorefrontRating::query()->updateOrCreate(
            ['product_id' => $product->id, 'user_id' => $userId],
            ['rating' => $rating],
        );

        $fresh = Product::query()->whereKey($product->id);
        $this->withProductStorefrontRatingAggregates($fresh, $userId);
        $product = $fresh->firstOrFail();

        return $this->publicProductPayload($product, $userId);
    }

    /**
     * Upsert a 1–5 star rating for a public storefront shop (Sanctum buyer).
     *
     * @return array<string, mixed>
     */
    public function rateShop(string $slug, int $userId, int $rating): array
    {
        if ($rating < 1 || $rating > 5) {
            throw ValidationException::withMessages([
                'rating' => ['Choose a rating from 1 to 5 stars.'],
            ]);
        }

        $business = $this->findEnabledShop($slug);

        BusinessStorefrontRating::query()->updateOrCreate(
            ['business_id' => $business->id, 'user_id' => $userId],
            ['rating' => $rating],
        );

        $fresh = Business::query()->whereKey($business->id);
        $this->withShopStorefrontRatingAggregates($fresh, $userId);
        $business = $fresh->firstOrFail();

        return $this->publicShopPayload($business, $userId);
    }

    public function shopWithRatings(int $businessId, ?int $viewerUserId = null): Business
    {
        $query = Business::query()->whereKey($businessId);
        $this->withShopStorefrontRatingAggregates($query, $viewerUserId);

        return $query->firstOrFail();
    }

    /**
     * @param  array{customer_name: string, customer_phone: string, notes?: string|null, items: list<array{product_id: int, quantity: int}>}  $payload
     */
    public function placeOrder(string $slug, array $payload): Order
    {
        $business = $this->findEnabledShop($slug);
        $ownerId = (int) ($business->owner_id ?? 0);
        if ($ownerId < 1) {
            throw ValidationException::withMessages([
                'shop' => ['This shop cannot accept orders right now.'],
            ]);
        }

        $items = $payload['items'] ?? [];
        if (!is_array($items) || count($items) < 1) {
            throw ValidationException::withMessages([
                'items' => ['Add at least one product to your order.'],
            ]);
        }

        $normalized = [];
        foreach ($items as $i => $item) {
            $productId = (int) ($item['product_id'] ?? 0);
            $qty = max(1, (int) ($item['quantity'] ?? 1));
            $product = Product::query()
                ->where('business_id', $business->id)
                ->where('id', $productId)
                ->where('listed_for_storefront', true)
                ->where('is_active', true)
                ->first();

            if (!$product) {
                throw ValidationException::withMessages([
                    "items.{$i}.product_id" => ['This product is no longer available in the shop.'],
                ]);
            }

            $isService = $product->isService();
            $stockQty = (int) ($product->stock_quantity ?? 0);

            if (!$isService && $qty > $stockQty) {
                throw ValidationException::withMessages([
                    "items.{$i}.quantity" => [
                        $stockQty < 1
                            ? 'This item is out of stock.'
                            : "Only {$stockQty} left in stock.",
                    ],
                ]);
            }

            $unit = $product->effectiveUnitPrice();
            $normalized[] = [
                'product_id' => $product->id,
                'product_name' => $product->name,
                'product_price' => $unit,
                'quantity' => $qty,
                'unit_price' => $unit,
                'discount_amount' => 0,
                'tax_amount' => 0,
                'subtotal' => round($unit * $qty, 2),
            ];
        }

        return $this->orderService->createFromStorefront($business, $ownerId, [
            'customer_name' => trim((string) $payload['customer_name']),
            'customer_phone' => trim((string) $payload['customer_phone']),
            'delivery_address' => isset($payload['delivery_address'])
                ? trim((string) $payload['delivery_address']) ?: null
                : null,
            'delivery_city' => isset($payload['delivery_city'])
                ? trim((string) $payload['delivery_city']) ?: null
                : null,
            'notes' => isset($payload['notes']) ? trim((string) $payload['notes']) : null,
            'items' => $normalized,
            'storefront_buyer_user_id' => $payload['storefront_buyer_user_id'] ?? null,
        ]);
    }

    /**
     * Orders the signed-in user placed as a storefront buyer (not the shop owner queue).
     *
     * @return LengthAwarePaginator<int, Order>
     */
    public function myOrders(int $buyerUserId, ?string $status, ?string $q, int $perPage = 24): LengthAwarePaginator
    {
        $query = Order::query()
            ->with([
                'business:id,name,slug,currency',
                'items',
                'sale:id,order_id,receipt_number,payment_status',
                'sale.linkedInvoice:id,sale_id,invoice_number',
            ])
            ->where('source', 'storefront')
            ->where('storefront_buyer_user_id', $buyerUserId)
            ->orderByDesc('id');

        if ($status !== null && trim($status) !== '') {
            $query->where('status', trim($status));
        }

        if ($q !== null && trim($q) !== '') {
            $term = '%'.trim($q).'%';
            $query->where(function (Builder $b) use ($term) {
                $b->where('order_number', 'like', $term)
                    ->orWhere('customer_name', 'like', $term)
                    ->orWhereHas('business', fn (Builder $bb) => $bb->where('name', 'like', $term));
            });
        }

        return $query->paginate(min(48, max(1, $perPage)));
    }

    /** @return array<string, mixed> */
    public function buyerOrderPayload(Order $order): array
    {
        $business = $order->business;
        $sale = $order->relationLoaded('sale') ? $order->sale : null;
        $invoice = $sale && $sale->relationLoaded('linkedInvoice') ? $sale->linkedInvoice : null;

        $items = $order->relationLoaded('items')
            ? $order->items->map(static fn ($item) => [
                'id' => $item->id,
                'product_id' => $item->product_id,
                'product_name' => $item->product_name,
                'quantity' => (int) $item->quantity,
                'unit_price' => $item->unit_price,
                'subtotal' => $item->subtotal,
            ])->values()->all()
            : [];

        return [
            'id' => $order->id,
            'order_number' => $order->order_number,
            'status' => $order->status,
            'total_amount' => $order->total_amount,
            'items_count' => count($items) > 0 ? count($items) : ($order->items?->count() ?? 0),
            'items' => $items,
            'customer_name' => $order->customer_name,
            'customer_phone' => $order->customer_phone,
            'delivery_address' => $order->delivery_address,
            'delivery_city' => $order->delivery_city,
            'notes' => $order->notes,
            'created_at' => $order->created_at?->toISOString(),
            'shop_name' => $business?->name,
            'shop_slug' => $business?->slug,
            'currency' => $business?->currency ?? 'UGX',
            'sale_id' => $sale?->id,
            'receipt_number' => $sale?->receipt_number,
            'payment_status' => $sale?->payment_status,
            'invoice_id' => $invoice?->id,
            'invoice_number' => $invoice?->invoice_number,
        ];
    }

    public function findBuyerOrder(int $buyerUserId, int $orderId): ?Order
    {
        return Order::query()
            ->where('source', 'storefront')
            ->where('storefront_buyer_user_id', $buyerUserId)
            ->where('id', $orderId)
            ->first();
    }

    public function cancelBuyerOrder(int $buyerUserId, int $orderId): Order
    {
        $order = $this->findBuyerOrder($buyerUserId, $orderId);
        if (!$order) {
            abort(404, 'Order not found.');
        }

        if (!$order->isOpen()) {
            throw ValidationException::withMessages([
                'status' => ['Only open orders can be cancelled.'],
            ]);
        }

        $order->status = Order::STATUS_CANCELLED;
        $order->save();

        return $order->fresh([
            'business:id,name,slug,currency',
            'items',
            'sale:id,order_id,receipt_number,payment_status',
            'sale.linkedInvoice:id,sale_id,invoice_number',
        ]) ?? $order;
    }

    public function deleteBuyerOrder(int $buyerUserId, int $orderId): void
    {
        $order = $this->findBuyerOrder($buyerUserId, $orderId);
        if (!$order) {
            abort(404, 'Order not found.');
        }

        if ($order->status !== Order::STATUS_CANCELLED) {
            throw ValidationException::withMessages([
                'status' => ['Only cancelled orders can be deleted.'],
            ]);
        }

        $order->delete();
    }

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
            ->whereHas('business', function (Builder $b) {
                $b->where('storefront_enabled', true)
                    ->where('status', 'active');
            });
    }
}
