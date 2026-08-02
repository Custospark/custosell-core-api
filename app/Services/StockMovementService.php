<?php

namespace App\Services;

use App\Models\LocationProduct;
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

            // Resolve the location: explicit, else the acting user's current location,
            // else the business default location.
            if (empty($data['location_id'])) {
                $data['location_id'] = $this->resolveLocationId($businessId, $data['created_by'] ?? null);
            }

            $movement = $this->stockMovementRepository->create($data);

            $this->syncLocationStock($businessId, $data['product_id'], $data['location_id'], $data['stock_after']);

            // Keep Product.stock_quantity in sync as the aggregate/default view.
            $product = Product::find($data['product_id']);
            if ($product) {
                $product->stock_quantity = $data['stock_after'];
                $product->save();
            }

            return $movement->fresh(['createdBy', 'product', 'location']);
        });
    }

    private function resolveLocationId(int $businessId, ?int $userId): ?int
    {
        if ($userId) {
            $userLocation = \App\Models\User::find($userId)?->location_id;
            if ($userLocation) {
                return $userLocation;
            }
        }

        return \App\Models\Location::forBusiness($businessId)->where('is_default', true)->value('id');
    }

    private function syncLocationStock(int $businessId, int $productId, ?int $locationId, int $stockAfter): void
    {
        if (!$locationId) {
            return;
        }

        LocationProduct::updateOrCreate(
            [
                'business_id' => $businessId,
                'location_id' => $locationId,
                'product_id' => $productId,
            ],
            ['stock_quantity' => $stockAfter],
        );
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
