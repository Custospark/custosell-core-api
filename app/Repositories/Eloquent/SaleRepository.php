<?php

namespace App\Repositories\Eloquent;

use App\Models\Sale;
use App\Repositories\Contracts\SaleRepositoryInterface;
use Illuminate\Database\Eloquent\Collection;

class SaleRepository implements SaleRepositoryInterface
{
    public function all(int $businessId): Collection
    {
        return Sale::where('business_id', $businessId)
            ->with(['user', 'customer', 'shift', 'saleItems', 'business'])
            ->orderBy('sale_date', 'desc')
            ->get();
    }

    public function find(int $id): ?Sale
    {
        return Sale::with(['user', 'customer', 'shift', 'saleItems', 'business'])->find($id);
    }

    public function findByReceipt(int $businessId, string $receiptNumber): ?Sale
    {
        return Sale::where('business_id', $businessId)
            ->where('receipt_number', $receiptNumber)
            ->first();
    }

    public function create(array $data): Sale
    {
        return Sale::create($data);
    }

    public function update(Sale $sale, array $data): Sale
    {
        $sale->update($data);
        return $sale->fresh();
    }

    public function delete(Sale $sale): bool
    {
        return $sale->delete();
    }

    public function getByDateRange(int $businessId, string $start, string $end): Collection
    {
        return Sale::where('business_id', $businessId)
            ->whereBetween('sale_date', [$start, $end])
            ->with(['user', 'customer', 'shift', 'saleItems', 'business'])
            ->orderBy('sale_date', 'desc')
            ->get();
    }

    public function getByShift(int $businessId, int $shiftId): Collection
    {
        return Sale::where('business_id', $businessId)
            ->where('shift_id', $shiftId)
            ->with(['user', 'customer', 'saleItems', 'business'])
            ->orderBy('sale_date', 'desc')
            ->get();
    }

    public function getByCustomer(int $businessId, int $customerId): Collection
    {
        return Sale::where('business_id', $businessId)
            ->where('customer_id', $customerId)
            ->with(['user', 'saleItems', 'business'])
            ->orderBy('sale_date', 'desc')
            ->get();
    }
}
