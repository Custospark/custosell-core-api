<?php

namespace App\Services;

use App\Models\Product;
use App\Models\StockMovement;
use App\Repositories\Contracts\StockMovementRepositoryInterface;
use App\Services\Contracts\StockMovementServiceInterface;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

class StockMovementService implements StockMovementServiceInterface
{
    public function __construct(
        protected StockMovementRepositoryInterface $stockMovementRepository,
    ) {}

    public function getAll(int $businessId): Collection
    {
        return $this->stockMovementRepository->all($businessId);
    }

    public function getById(int $id): ?StockMovement
    {
        return $this->stockMovementRepository->find($id);
    }

    public function create(int $businessId, array $data): StockMovement
    {
        return DB::transaction(function () use ($businessId, $data) {
            $data['business_id'] = $businessId;
            if (empty($data['created_by']) && auth()->id()) {
                $data['created_by'] = auth()->id();
            }

            $movement = $this->stockMovementRepository->create($data);

            $product = Product::find($data['product_id']);
            if ($product) {
                $product->stock_quantity = $data['stock_after'];
                $product->save();
            }

            return $movement->fresh(['createdBy', 'product']);
        });
    }

    public function update(int $id, array $data): StockMovement
    {
        $stockMovement = $this->stockMovementRepository->find($id);
        if (!$stockMovement) {
            throw new \RuntimeException('Stock movement not found');
        }
        return $this->stockMovementRepository->update($stockMovement, $data);
    }

    public function delete(int $id): bool
    {
        $stockMovement = $this->stockMovementRepository->find($id);
        if (!$stockMovement) {
            throw new \RuntimeException('Stock movement not found');
        }
        return $this->stockMovementRepository->delete($stockMovement);
    }

    public function bulkDelete(array $ids, int $businessId): int
    {
        return \App\Models\StockMovement::whereIn('id', $ids)
            ->where('business_id', $businessId)
            ->delete();
    }

    public function getByProduct(int $businessId, int $productId): Collection
    {
        return $this->stockMovementRepository->getByProduct($businessId, $productId);
    }

    public function getByType(int $businessId, string $type): Collection
    {
        return $this->stockMovementRepository->getByType($businessId, $type);
    }
}
