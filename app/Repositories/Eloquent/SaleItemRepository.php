<?php

namespace App\Repositories\Eloquent;

use App\Models\SaleItem;
use App\Repositories\Contracts\SaleItemRepositoryInterface;
use Illuminate\Database\Eloquent\Collection;

class SaleItemRepository implements SaleItemRepositoryInterface
{
    public function all(): Collection
    {
        return SaleItem::with('product')->get();
    }

    public function find(int $id): ?SaleItem
    {
        return SaleItem::with('product')->find($id);
    }

    public function create(array $data): SaleItem
    {
        return SaleItem::create($data);
    }

    public function update(SaleItem $saleItem, array $data): SaleItem
    {
        $saleItem->update($data);
        return $saleItem->fresh();
    }

    public function delete(SaleItem $saleItem): bool
    {
        return $saleItem->delete();
    }

    public function getBySale(int $saleId): Collection
    {
        return SaleItem::where('sale_id', $saleId)
            ->with('product')
            ->get();
    }
}
