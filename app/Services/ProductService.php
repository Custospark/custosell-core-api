<?php

namespace App\Services;

use App\Models\Product;
use App\Repositories\Contracts\ProductRepositoryInterface;
use App\Services\Contracts\ProductServiceInterface;
use App\Services\Contracts\SubscriptionServiceInterface;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Validation\ValidationException;

class ProductService implements ProductServiceInterface
{
    public function __construct(
        protected ProductRepositoryInterface $productRepository,
        protected SubscriptionServiceInterface $subscriptionService,
    ) {}

    public function getAll(int $businessId): Collection
    {
        return $this->productRepository->all($businessId);
    }

    public function getAllForLocation(int $businessId, ?int $locationId): Collection
    {
        return $this->productRepository->allWithLocationStock($businessId, $locationId);
    }

    public function getById(int $id): ?Product
    {
        return $this->productRepository->find($id);
    }

    public function create(int $businessId, array $data): Product
    {
        $subscription = $this->subscriptionService->getByBusiness($businessId);
        $limits = $subscription?->plan?->limits ?? [];
        $maxProducts = $limits['max_products'] ?? null;

        if ($maxProducts !== null) {
            $currentCount = $this->productRepository->countByBusiness($businessId);
            if ($currentCount >= $maxProducts) {
                throw ValidationException::withMessages([
                    'plan_limit' => "You have reached the maximum of {$maxProducts} products. Upgrade your plan to add more.",
                ]);
            }
        }

        $data['business_id'] = $businessId;
        $data = $this->normalizeCatalogType($data);
        $data['listed_for_supply'] = (bool) ($data['listed_for_supply'] ?? true);
        $data['listed_for_storefront'] = (bool) ($data['listed_for_storefront'] ?? true);

        $locationId = isset($data['location_id'])
            ? (int) $data['location_id']
            : \App\Services\LocationService::ensureDefault($businessId)?->id;

        unset($data['location_id']);

        $product = $this->productRepository->create($data);

        $stockQty = (int) ($product->stock_quantity ?? 0);
        if ($stockQty > 0 && $locationId) {
            $this->recordInitialStock($businessId, $product->id, $locationId, $stockQty);
        }

        return $product;
    }

    private function recordInitialStock(int $businessId, int $productId, int $locationId, int $stockQty): void
    {
        \App\Models\LocationProduct::updateOrCreate(
            [
                'business_id' => $businessId,
                'location_id' => $locationId,
                'product_id' => $productId,
            ],
            ['stock_quantity' => $stockQty],
        );

        \App\Models\StockMovement::create([
            'business_id' => $businessId,
            'product_id' => $productId,
            'location_id' => $locationId,
            'type' => 'initial',
            'quantity_change' => $stockQty,
            'stock_before' => 0,
            'stock_after' => $stockQty,
            'reference' => 'product-create',
            'notes' => 'Initial stock on creation',
            'created_by' => auth()->id(),
        ]);
    }

    public function update(int $id, array $data): Product
    {
        $product = $this->productRepository->find($id);
        if (!$product) {
            throw new \RuntimeException('Product not found');
        }
        $data = $this->normalizeCatalogType($data, $product);

        return $this->productRepository->update($product, $data);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function normalizeCatalogType(array $data, ?Product $existing = null): array
    {
        $type = $data['type'] ?? $existing?->type ?? Product::TYPE_PRODUCT;
        $data['type'] = $type === Product::TYPE_SERVICE ? Product::TYPE_SERVICE : Product::TYPE_PRODUCT;

        if ($data['type'] === Product::TYPE_SERVICE) {
            $data['stock_quantity'] = 0;
        }

        return $data;
    }

    public function delete(int $id): bool
    {
        $product = $this->productRepository->find($id);
        if (!$product) {
            throw new \RuntimeException('Product not found');
        }
        $product->forceDelete();

        return true;
    }

    public function bulkDelete(array $ids, int $businessId): int
    {
        return \App\Models\Product::whereIn('id', $ids)
            ->where('business_id', $businessId)
            ->forceDelete();
    }

    public function bulkUpdateListing(array $ids, int $businessId, string $channel, bool $listed): int
    {
        $products = Product::query()
            ->where('business_id', $businessId)
            ->whereIn('id', $ids)
            ->get();

        foreach ($products as $product) {
            if ($channel === 'supply') {
                $product->listed_for_supply = $listed;
                if ($listed) {
                    if ($product->supply_price === null) {
                        $product->supply_price = $product->wholesale_price ?? $product->unit_price;
                    }
                    if ($product->supply_min_qty === null) {
                        $product->supply_min_qty = 1;
                    }
                    $product->listed_at = $product->listed_at ?? now();
                } else {
                    $product->listed_at = null;
                }
            } else {
                $product->listed_for_storefront = $listed;
                if ($listed) {
                    $product->storefront_listed_at = $product->storefront_listed_at ?? now();
                } else {
                    $product->storefront_listed_at = null;
                }
            }
            $product->save();
        }

        return $products->count();
    }

    public function getActive(int $businessId): Collection
    {
        return $this->productRepository->getActive($businessId);
    }

    public function getLowStock(int $businessId): Collection
    {
        return $this->productRepository->getLowStock($businessId);
    }

    public function updateSupplyListing(int $id, int $businessId, array $data): Product
    {
        $product = Product::where('business_id', $businessId)->findOrFail($id);

        $listed = (bool) ($data['listed_for_supply'] ?? $product->listed_for_supply);

        $product->listed_for_supply = $listed;
        $product->supply_price = $data['supply_price'] ?? $product->supply_price;
        $product->supply_min_qty = $data['supply_min_qty'] ?? $product->supply_min_qty ?? 1;
        $product->listed_at = $listed ? ($product->listed_at ?? now()) : null;
        $product->save();

        return $product;
    }

    public function updateStorefrontListing(int $id, int $businessId, array $data): Product
    {
        $product = Product::where('business_id', $businessId)->findOrFail($id);
        $listed = (bool) ($data['listed_for_storefront'] ?? false);
        $product->listed_for_storefront = $listed;
        $product->storefront_listed_at = $listed ? ($product->storefront_listed_at ?? now()) : null;
        $product->save();

        return $product;
    }

    public function updateImage(int $id, int $businessId, string $imagePath): Product
    {
        $product = Product::where('business_id', $businessId)->findOrFail($id);
        $product->image_path = $imagePath;
        $product->save();

        return $product;
    }

    public function getStockByLocation(int $businessId, int $locationId): Collection
    {
        return \App\Models\LocationProduct::with('product')
            ->where('business_id', $businessId)
            ->where('location_id', $locationId)
            ->get();
    }
}
