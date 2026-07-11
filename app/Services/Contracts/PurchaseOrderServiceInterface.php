<?php

namespace App\Services\Contracts;

use App\Models\PurchaseOrder;
use Illuminate\Database\Eloquent\Collection;

interface PurchaseOrderServiceInterface
{
    /** Purchase orders raised by this business as buyer. */
    public function getAllForBuyer(int $buyerBusinessId, array $filters = []): Collection;

    /** Purchase orders received by this business as seller (drafts excluded). */
    public function getIncomingForSeller(int $sellerBusinessId, array $filters = []): Collection;

    /** Fetch a PO only if the given business is its buyer or seller. */
    public function getVisibleForBusiness(int $id, int $businessId): ?PurchaseOrder;

    public function create(int $buyerBusinessId, int $userId, array $data): PurchaseOrder;

    public function update(int $id, int $buyerBusinessId, array $data): PurchaseOrder;

    public function submit(int $id, int $buyerBusinessId): PurchaseOrder;

    public function cancel(int $id, int $buyerBusinessId): PurchaseOrder;

    public function accept(int $id, int $sellerBusinessId, int $sellerUserId): PurchaseOrder;

    public function reject(int $id, int $sellerBusinessId, string $reason): PurchaseOrder;

    public function delete(int $id, int $businessId): void;

    public function fulfill(int $id, int $sellerBusinessId, ?int $userId): PurchaseOrder;

    /**
     * @param  array<int, array{id: int, product_id: int}>  $itemMappings
     */
    public function receive(int $id, int $buyerBusinessId, ?int $userId, array $itemMappings): PurchaseOrder;
}
