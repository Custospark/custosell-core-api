<?php

namespace App\Services\Contracts;

use App\Models\Sale;
use Illuminate\Database\Eloquent\Collection;

interface SaleServiceInterface
{
    public function getAll(int $businessId): Collection;

    public function getById(int $id): ?Sale;

    public function create(int $businessId, int $userId, array $data): Sale;

    public function update(int $id, array $data): Sale;

    public function delete(int $id): bool;

    public function getByDateRange(int $businessId, string $start, string $end): Collection;

    public function getByShift(int $shiftId): Collection;
}
