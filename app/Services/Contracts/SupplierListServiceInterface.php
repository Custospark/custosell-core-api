<?php

namespace App\Services\Contracts;

use App\Models\Business;
use Illuminate\Database\Eloquent\Collection;

interface SupplierListServiceInterface
{
    /** Saved suppliers for this buyer business. */
    public function listForBuyer(int $buyerBusinessId, ?string $q = null): Collection;

    public function add(int $buyerBusinessId, int $sellerBusinessId, ?string $notes = null): Business;

    public function remove(int $buyerBusinessId, int $sellerBusinessId): void;

    /** @return list<int> */
    public function savedSellerIds(int $buyerBusinessId): array;

    /**
     * Attach is_saved + listed_products_count for marketplace UI.
     *
     * @param  Collection<int, Business>  $businesses
     * @return Collection<int, Business>
     */
    public function annotate(Collection $businesses, int $buyerBusinessId, bool $forceSaved = false): Collection;
}
