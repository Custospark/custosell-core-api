<?php

namespace App\Services;

use App\Models\Business;
use App\Models\BusinessSupplierListEntry;
use App\Models\Product;
use App\Services\Contracts\SupplierListServiceInterface;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SupplierListService implements SupplierListServiceInterface
{
    public function listForBuyer(int $buyerBusinessId, ?string $q = null): Collection
    {
        $sellerIds = BusinessSupplierListEntry::query()
            ->where('buyer_business_id', $buyerBusinessId)
            ->orderByDesc('id')
            ->pluck('seller_business_id');

        if ($sellerIds->isEmpty()) {
            return new Collection;
        }

        $query = Business::query()
            ->whereIn('id', $sellerIds)
            ->orderBy('name');

        if ($q !== null && trim($q) !== '') {
            $term = trim($q);
            $query->where(function ($builder) use ($term) {
                $builder->where('name', 'like', "%{$term}%")
                    ->orWhere('supply_headline', 'like', "%{$term}%");
            });
        }

        $businesses = $query->get();

        return $this->annotate($businesses, $buyerBusinessId, true);
    }

    public function add(int $buyerBusinessId, int $sellerBusinessId, ?string $notes = null): Business
    {
        if ($buyerBusinessId === $sellerBusinessId) {
            throw ValidationException::withMessages([
                'seller_business_id' => ['You cannot add your own business as a supplier.'],
            ]);
        }

        $seller = Business::find($sellerBusinessId);
        if (! $seller) {
            throw ValidationException::withMessages([
                'seller_business_id' => ['Supplier business was not found.'],
            ]);
        }

        if (! $seller->isOpenForSupply()) {
            throw ValidationException::withMessages([
                'seller_business_id' => ['This business is not open for supply.'],
            ]);
        }

        BusinessSupplierListEntry::query()->firstOrCreate(
            [
                'buyer_business_id' => $buyerBusinessId,
                'seller_business_id' => $sellerBusinessId,
            ],
            [
                'notes' => $notes,
            ],
        );

        $annotated = $this->annotate(new Collection([$seller->fresh()]), $buyerBusinessId, true);

        return $annotated->first();
    }

    public function remove(int $buyerBusinessId, int $sellerBusinessId): void
    {
        $deleted = BusinessSupplierListEntry::query()
            ->where('buyer_business_id', $buyerBusinessId)
            ->where('seller_business_id', $sellerBusinessId)
            ->delete();

        if ($deleted < 1) {
            throw ValidationException::withMessages([
                'seller_business_id' => ['That supplier is not on your list.'],
            ]);
        }
    }

    public function savedSellerIds(int $buyerBusinessId): array
    {
        return BusinessSupplierListEntry::query()
            ->where('buyer_business_id', $buyerBusinessId)
            ->pluck('seller_business_id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    /**
     * @param  Collection<int, Business>  $businesses
     * @return Collection<int, Business>
     */
    public function annotate(Collection $businesses, int $buyerBusinessId, bool $forceSaved = false): Collection
    {
        if ($businesses->isEmpty()) {
            return $businesses;
        }

        $saved = $forceSaved
            ? $businesses->pluck('id')->map(fn ($id) => (int) $id)->all()
            : $this->savedSellerIds($buyerBusinessId);

        $savedLookup = array_fill_keys($saved, true);
        $ids = $businesses->pluck('id')->all();

        $counts = Product::query()
            ->select('business_id', DB::raw('COUNT(*) as listed_count'))
            ->whereIn('business_id', $ids)
            ->where('type', Product::TYPE_PRODUCT)
            ->where('is_active', true)
            ->where('listed_for_supply', true)
            ->groupBy('business_id')
            ->pluck('listed_count', 'business_id');

        foreach ($businesses as $business) {
            $business->setAttribute('is_saved', isset($savedLookup[(int) $business->id]));
            $business->setAttribute('listed_products_count', (int) ($counts[$business->id] ?? 0));
        }

        return $businesses;
    }
}
