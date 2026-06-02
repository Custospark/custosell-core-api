<?php

namespace App\Services;

use App\Models\SaleItem;
use App\Repositories\Contracts\SaleItemRepositoryInterface;
use App\Services\Contracts\SaleItemServiceInterface;
use Illuminate\Database\Eloquent\Collection;

class SaleItemService implements SaleItemServiceInterface
{
    public function __construct(
        protected SaleItemRepositoryInterface $saleItemRepository,
    ) {}

    public function getAll(): Collection
    {
        return $this->saleItemRepository->all();
    }

    public function getById(int $id): ?SaleItem
    {
        return $this->saleItemRepository->find($id);
    }

    public function create(array $data): SaleItem
    {
        return $this->saleItemRepository->create($data);
    }

    public function update(int $id, array $data): SaleItem
    {
        $saleItem = $this->saleItemRepository->find($id);
        if (!$saleItem) {
            throw new \RuntimeException('Sale item not found');
        }
        return $this->saleItemRepository->update($saleItem, $data);
    }

    public function delete(int $id): bool
    {
        $saleItem = $this->saleItemRepository->find($id);
        if (!$saleItem) {
            throw new \RuntimeException('Sale item not found');
        }
        return $this->saleItemRepository->delete($saleItem);
    }

    public function getBySale(int $saleId): Collection
    {
        return $this->saleItemRepository->getBySale($saleId);
    }
}
