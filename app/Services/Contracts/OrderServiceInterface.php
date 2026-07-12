<?php

namespace App\Services\Contracts;

use App\Models\Business;
use App\Models\Order;
use Illuminate\Database\Eloquent\Collection;

interface OrderServiceInterface
{
    public function getAll(int $businessId, array $filters = []): Collection;

    public function getById(int $id): ?Order;

    public function create(int $businessId, int $userId, array $data): Order;

    /**
     * Guest storefront order — attributed to business owner; no stock reservation.
     *
     * @param  array{customer_name: string, customer_phone: string, notes?: string|null, items: list<array<string, mixed>>}  $data
     */
    public function createFromStorefront(Business $business, int $ownerUserId, array $data): Order;

    public function update(int $id, int $businessId, array $data): Order;

    public function cancel(int $id, int $businessId): Order;

    public function assertOrderOpenForSale(int $orderId, int $businessId): void;

    public function completeFromSale(int $orderId, int $businessId, int $saleId): Order;

    public function markInvoicedForSale(int $saleId): void;
}
