<?php

namespace App\Services\Contracts;

use App\Models\Order;
use Illuminate\Database\Eloquent\Collection;

interface OrderServiceInterface
{
    public function getAll(int $businessId, array $filters = []): Collection;

    public function getById(int $id): ?Order;

    public function create(int $businessId, int $userId, array $data): Order;

    public function update(int $id, int $businessId, array $data): Order;

    public function cancel(int $id, int $businessId): Order;

    public function assertOrderOpenForSale(int $orderId, int $businessId): void;

    public function completeFromSale(int $orderId, int $businessId, int $saleId): Order;

    public function markInvoicedForSale(int $saleId): void;
}
