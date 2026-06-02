<?php

namespace App\Services;

use App\Models\StockMovement;
use App\Repositories\Contracts\StockMovementRepositoryInterface;
use App\Services\Contracts\StockMovementServiceInterface;
use Illuminate\Database\Eloquent\Collection;

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
        $data['business_id'] = $businessId;
        return $this->stockMovementRepository->create($data);
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

    public function getByProduct(int $businessId, int $productId): Collection
    {
        return $this->stockMovementRepository->getByProduct($businessId, $productId);
    }

    public function getByType(int $businessId, string $type): Collection
    {
        return $this->stockMovementRepository->getByType($businessId, $type);
    }
}
