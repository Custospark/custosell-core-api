<?php

namespace App\Repositories\Contracts;

use App\Models\SaleItem;
use Illuminate\Database\Eloquent\Collection;

interface SaleItemRepositoryInterface
{
    public function all(): Collection;

    public function find(int $id): ?SaleItem;

    public function create(array $data): SaleItem;

    public function update(SaleItem $saleItem, array $data): SaleItem;

    public function delete(SaleItem $saleItem): bool;

    public function getBySale(int $saleId): Collection;
}
