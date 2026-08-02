<?php

namespace App\Services\Contracts;

use App\Models\Product;
use Illuminate\Database\Eloquent\Collection;

interface ProductServiceInterface
{
    public function getAll(int $businessId): Collection;

    public function getAllForLocation(int $businessId, ?int $locationId): Collection;

    public function getById(int $id): ?Product;

    public function create(int $businessId, array $data): Product;

    public function update(int $id, array $data): Product;

    public function delete(int $id): bool;

    public function bulkDelete(array $ids, int $businessId): int;

    /**
     * Bulk list/unlist products for a sales channel (supply or storefront).
     *
     * @param  array<int>  $ids
     * @return int Number of the business's products that were updated.
     */
    public function bulkUpdateListing(array $ids, int $businessId, string $channel, bool $listed): int;

    public function getActive(int $businessId): Collection;

    public function getLowStock(int $businessId): Collection;

    /** Update the supply-marketplace listing for a product owned by the given business. */
    public function updateSupplyListing(int $id, int $businessId, array $data): Product;

    public function updateStorefrontListing(int $id, int $businessId, array $data): Product;

    public function updateImage(int $id, int $businessId, string $imagePath): Product;

    public function getStockByLocation(int $businessId, int $locationId): Collection;
}
