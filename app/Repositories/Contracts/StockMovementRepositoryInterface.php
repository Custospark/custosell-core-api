<?php

namespace App\Repositories\Contracts;

use App\Models\StockMovement;
use Illuminate\Database\Eloquent\Collection;

interface StockMovementRepositoryInterface
{
    public function all(int $businessId): Collection;

    public function find(int $id): ?StockMovement;

    public function create(array $data): StockMovement;

    public function update(StockMovement $stockMovement, array $data): StockMovement;

    public function delete(StockMovement $stockMovement): bool;

    public function getByProduct(int $businessId, int $productId): Collection;

    public function getByType(int $businessId, string $type): Collection;
}
