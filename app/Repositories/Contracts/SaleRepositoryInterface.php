<?php

namespace App\Repositories\Contracts;

use App\Models\Sale;
use Illuminate\Database\Eloquent\Collection;

interface SaleRepositoryInterface
{
    public function all(int $businessId): Collection;

    public function find(int $id): ?Sale;

    public function findByReceipt(int $businessId, string $receiptNumber): ?Sale;

    public function create(array $data): Sale;

    public function update(Sale $sale, array $data): Sale;

    public function delete(Sale $sale): bool;

    public function getByDateRange(int $businessId, string $start, string $end): Collection;

    public function getByShift(int $shiftId): Collection;

    public function getByCustomer(int $businessId, int $customerId): Collection;
}
