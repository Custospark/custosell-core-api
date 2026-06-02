<?php

namespace App\Repositories\Eloquent;

use App\Models\StockMovement;
use App\Repositories\Contracts\StockMovementRepositoryInterface;
use Illuminate\Database\Eloquent\Collection;

class StockMovementRepository implements StockMovementRepositoryInterface
{
    public function all(int $businessId): Collection
    {
        return StockMovement::where('business_id', $businessId)
            ->with(['product', 'createdBy'])
            ->orderBy('created_at', 'desc')
            ->get();
    }

    public function find(int $id): ?StockMovement
    {
        return StockMovement::with(['product', 'createdBy'])->find($id);
    }

    public function create(array $data): StockMovement
    {
        return StockMovement::create($data);
    }

    public function update(StockMovement $stockMovement, array $data): StockMovement
    {
        $stockMovement->update($data);
        return $stockMovement->fresh();
    }

    public function delete(StockMovement $stockMovement): bool
    {
        return $stockMovement->delete();
    }

    public function getByProduct(int $businessId, int $productId): Collection
    {
        return StockMovement::where('business_id', $businessId)
            ->where('product_id', $productId)
            ->with('createdBy')
            ->orderBy('created_at', 'desc')
            ->get();
    }

    public function getByType(int $businessId, string $type): Collection
    {
        return StockMovement::where('business_id', $businessId)
            ->where('type', $type)
            ->with(['product', 'createdBy'])
            ->orderBy('created_at', 'desc')
            ->get();
    }
}
