<?php

namespace App\Services\Contracts;

use App\Models\Invoice;
use Illuminate\Database\Eloquent\Collection;

interface InvoiceServiceInterface
{
    public function getAll(int $businessId, array $filters = []): Collection;

    public function getById(int $id): ?Invoice;

    public function getVisibleForBusiness(int $id, int $businessId): ?Invoice;

    public function isOwnedByBusiness(Invoice $invoice, int $businessId): bool;

    public function canManagePayments(Invoice $invoice, int $businessId): bool;

    public function create(int $businessId, int $userId, array $data): Invoice;

    /** Create and send a seller invoice from an accepted purchase order. */
    public function createFromPurchaseOrder(\App\Models\PurchaseOrder $po, int $sellerUserId): Invoice;

    public function update(int $id, array $data): Invoice;

    public function delete(int $id): bool;

    public function send(int $id): Invoice;

    public function recordPayment(int $id, float $amount, string $paymentMethod, int $userId): array;
}
