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

        return \App\Services\LocationService::ensureDefault($businessId)?->id;
    }

    private function syncLocationStock(int $businessId, int $productId, ?int $locationId, float $stockAfter): void
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

    public function transfer(
        int $businessId,
        int $fromLocationId,
        int $toLocationId,
        array $items,
        ?int $userId = null
    ): array {
        return DB::transaction(function () use ($businessId, $fromLocationId, $toLocationId, $items, $userId) {
            if ($fromLocationId === $toLocationId) {
                throw new \InvalidArgumentException('Source and destination branch must be different.');
            }

            $fromLocation = \App\Models\Location::forBusiness($businessId)->find($fromLocationId);
            $toLocation = \App\Models\Location::forBusiness($businessId)->find($toLocationId);
            if (!$fromLocation) {
                throw new \RuntimeException('Source branch not found.');
            }
            if (!$toLocation) {
                throw new \RuntimeException('Destination branch not found.');
            }
            if (!$fromLocation->is_active && !$fromLocation->is_default) {
                throw new \RuntimeException('Source branch is inactive.');
            }
            if (!$toLocation->is_active && !$toLocation->is_default) {
                throw new \RuntimeException('Destination branch is inactive.');
            }

            $movements = [];
            $verified = 0;

            foreach ($items as $item) {
                $productId = (int) ($item['product_id'] ?? 0);
                $quantity = (int) ($item['quantity'] ?? 0);

                if ($quantity <= 0) {
                    throw new \InvalidArgumentException('Transfer quantity must be greater than zero.');
                }

                $product = \App\Models\Product::where('business_id', $businessId)
                    ->where('id', $productId)
                    ->first();
                if (!$product) {
                    throw new \RuntimeException("Product #{$productId} does not belong to this business.");
                }

                // Services are not quantitative - never hold branch stock, so skip transfers.
                if (!$product->tracksStock()) {
                    continue;
                }

                $source = LocationProduct::where('business_id', $businessId)
                    ->where('location_id', $fromLocationId)
                    ->where('product_id', $productId)
                    ->first();

                $available = $source ? (int) $source->stock_quantity : 0;
                if ($available < $quantity) {
                    throw new \RuntimeException(sprintf(
                        'Insufficient stock in %s for product #%d. Available: %d, requested: %d.',
                        $fromLocation->name,
                        $productId,
                        $available,
                        $quantity
                    ));
                }

                $destination = LocationProduct::where('business_id', $businessId)
                    ->where('location_id', $toLocationId)
                    ->where('product_id', $productId)
                    ->first();

                $sourceAfter = $available - $quantity;
                $destinationAfter = $destination ? (int) $destination->stock_quantity : 0;

                $sourceStock = $source ?? new LocationProduct([
                    'business_id' => $businessId,
                    'location_id' => $fromLocationId,
                    'product_id' => $productId,
                ]);
                $sourceStock->stock_quantity = $sourceAfter;
                $sourceStock->save();

                $destinationStock = $destination ?? new LocationProduct([
                    'business_id' => $businessId,
                    'location_id' => $toLocationId,
                    'product_id' => $productId,
                ]);
                $destinationStock->stock_quantity += $quantity;
                $destinationStock->save();

                $movement = $this->stockMovementRepository->create([
                    'business_id' => $businessId,
                    'location_id' => $fromLocationId,
                    'to_location_id' => $toLocationId,
                    'product_id' => $productId,
                    'type' => 'transfer',
                    'quantity_change' => -$quantity,
                    'stock_before' => $available,
                    'stock_after' => $sourceAfter,
                    'reference' => 'branch-transfer',
                    'notes' => "Transferred {$quantity} to {$toLocation->name}",
                    'created_by' => $userId ?? auth()->id(),
                ]);

                $details = $destinationAfter + $quantity;
                $movements[] = [
                    'movement' => $movement,
                    'product' => $productId,
                    'from' => $fromLocationId,
                    'from_qty' => $sourceAfter,
                    'to' => $toLocationId,
                    'to_qty' => $details,
                ];
                $verified++;
            }

            return [
                'count' => $verified,
                'from_location_id' => $fromLocationId,
                'to_location_id' => $toLocationId,
                'movements' => $movements,
            ];
        });
    }
}
