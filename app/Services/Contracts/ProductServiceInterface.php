<?php

namespace App\Services\Contracts;

use App\Models\Product;
use Illuminate\Database\Eloquent\Collection;

interface ProductServiceInterface
{
    public function getAll(int $businessId): Collection;

    public function getById(int $id): ?Product;

    public function create(int $businessId, array $data): Product;

    public function update(int $id, array $data): Product;

    public function delete(int $id): bool;

    public function getActive(int $businessId): Collection;

    public function getLowStock(int $businessId): Collection;
}
