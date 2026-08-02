<?php

namespace App\Repositories\Eloquent;

use App\Models\Product;
use App\Repositories\Contracts\ProductRepositoryInterface;
use Illuminate\Database\Eloquent\Collection;

class ProductRepository implements ProductRepositoryInterface
{
    public function all(int $businessId): Collection
    {
        return Product::where('business_id', $businessId)
            ->with('category')
            ->get();
    }

    public function allWithLocationStock(int $businessId, ?int $locationId): Collection
    {
        $query = Product::where('business_id', $businessId)->with('category');

        if ($locationId) {
            $query->with([
                'locationStock' => fn ($q) => $q->where('location_id', $locationId),
            ]);
        }

        $products = $query->get();

        if ($locationId) {
            foreach ($products as $product) {
                $local = $product->locationStock->first();
                if (!$local) {
                    continue;
                }
                $product->stock_quantity = (int) $local->stock_quantity;
                $product->low_stock_threshold = (int) $local->low_stock_threshold;
            }
        }

        return $products;
    }

    public function find(int $id): ?Product
    {
        return Product::with('category')->find($id);
    }

    public function findBySku(int $businessId, string $sku): ?Product
    {
        return Product::where('business_id', $businessId)
            ->where('sku', $sku)
            ->first();
    }

    public function countByBusiness(int $businessId): int
    {
        return Product::where('business_id', $businessId)->count();
    }

    public function create(array $data): Product
    {
        return Product::create($data);
    }

    public function update(Product $product, array $data): Product
    {
        $product->update($data);
        return $product->fresh();
    }

    public function delete(Product $product): bool
    {
        return $product->delete();
    }

    public function getActive(int $businessId): Collection
    {
        return Product::where('business_id', $businessId)
            ->where('is_active', true)
            ->with('category')
            ->get();
    }

    public function getLowStock(int $businessId): Collection
    {
        return Product::where('business_id', $businessId)
            ->where('is_active', true)
            ->where(function ($q) {
                $q->where('type', Product::TYPE_PRODUCT)
                    ->orWhereNull('type');
            })
            ->whereColumn('stock_quantity', '<=', 'low_stock_threshold')
            ->with('category')
            ->get();
    }
}
