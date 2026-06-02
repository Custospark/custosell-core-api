<?php

namespace App\Repositories\Contracts;

use App\Models\Product;
use Illuminate\Database\Eloquent\Collection;

interface ProductRepositoryInterface
{
    public function all(int $businessId): Collection;

    public function find(int $id): ?Product;

    public function findBySku(int $businessId, string $sku): ?Product;

    public function create(array $data): Product;

    public function update(Product $product, array $data): Product;

    public function delete(Product $product): bool;

    public function getActive(int $businessId): Collection;

    public function getLowStock(int $businessId): Collection;
}
