<?php

declare(strict_types=1);

namespace App\Services\Storefront;

use App\Models\Business;
use App\Models\Category;
use App\Models\Order;
use App\Models\Product;
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
    public function publicShopPayload(Business $business): array
    {
        return [
            'name' => $business->name,
            'slug' => $business->slug,
            'description' => $business->description,
            'logo_path' => $business->logo_path,
            'city' => $business->city,
            'country' => $business->country,
            'business_phone' => $business->business_phone ?? $business->phone,
            'currency' => $business->currency ?? 'UGX',
        ];
    }

    public function discoverProducts(?string $q, ?string $category, int $perPage = 24): LengthAwarePaginator
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

    public function shopProducts(Business $business, ?string $category = null): Collection
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

        return $query->orderBy('name')->get();
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

            $unit = (float) $product->unit_price;
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
            'notes' => isset($payload['notes']) ? trim((string) $payload['notes']) : null,
            'items' => $normalized,
        ]);
    }

    /** @return array<string, mixed> */
    public function publicProductPayload(Product $product): array
    {
        return [
            'id' => $product->id,
            'name' => $product->name,
            'description' => $product->description,
            'unit_price' => $product->unit_price,
            'unit' => $product->unit,
            'image_path' => $product->image_path,
            'type' => $product->type ?? 'product',
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
