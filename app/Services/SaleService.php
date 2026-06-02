<?php

namespace App\Services;

use App\Models\Sale;
use App\Repositories\Contracts\SaleRepositoryInterface;
use App\Services\Contracts\SaleServiceInterface;
use Illuminate\Database\Eloquent\Collection;

class SaleService implements SaleServiceInterface
{
    public function __construct(
        protected SaleRepositoryInterface $saleRepository,
    ) {}

    public function getAll(int $businessId): Collection
    {
        return $this->saleRepository->all($businessId);
    }

    public function getById(int $id): ?Sale
    {
        return $this->saleRepository->find($id);
    }

    public function create(int $businessId, int $userId, array $data): Sale
    {
        $data['business_id'] = $businessId;
        $data['user_id'] = $userId;
        return $this->saleRepository->create($data);
    }

    public function update(int $id, array $data): Sale
    {
        $sale = $this->saleRepository->find($id);
        if (!$sale) {
            throw new \RuntimeException('Sale not found');
        }
        return $this->saleRepository->update($sale, $data);
    }

    public function delete(int $id): bool
    {
        $sale = $this->saleRepository->find($id);
        if (!$sale) {
            throw new \RuntimeException('Sale not found');
        }
        return $this->saleRepository->delete($sale);
    }

    public function getByDateRange(int $businessId, string $start, string $end): Collection
    {
        return $this->saleRepository->getByDateRange($businessId, $start, $end);
    }

    public function getByShift(int $shiftId): Collection
    {
        return $this->saleRepository->getByShift($shiftId);
    }
}
