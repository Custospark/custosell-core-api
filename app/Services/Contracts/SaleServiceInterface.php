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

    public function bulkDelete(array $ids, int $businessId): int;

    public function getByDateRange(int $businessId, string $start, string $end): Collection;

    public function getByShift(int $businessId, int $shiftId): Collection;

    public function refund(int $id, array $data, ?int $actorUserId = null): Sale;

    public function getDaily(int $businessId, ?string $date = null): Collection;

    public function getByCustomer(int $businessId, int $customerId): Collection;

    public function recordPayment(int $id, float $amount, string $paymentMethod, int $userId): \App\Models\Payment;
}
