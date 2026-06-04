<?php

namespace App\Services\Contracts;

use App\Models\StockMovement;
use Illuminate\Database\Eloquent\Collection;

interface StockMovementServiceInterface
{
    public function getAll(int $businessId): Collection;

    public function getById(int $id): ?StockMovement;

    public function create(int $businessId, array $data): StockMovement;

    public function update(int $id, array $data): StockMovement;

    public function delete(int $id): bool;

    public function bulkDelete(array $ids, int $businessId): int;

    public function getByProduct(int $businessId, int $productId): Collection;

    public function getByType(int $businessId, string $type): Collection;
}
