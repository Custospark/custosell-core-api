<?php

namespace App\Services\Contracts;

use App\Models\SaleItem;
use Illuminate\Database\Eloquent\Collection;

interface SaleItemServiceInterface
{
    public function getAll(): Collection;

    public function getById(int $id): ?SaleItem;

    public function create(array $data): SaleItem;

    public function update(int $id, array $data): SaleItem;

    public function delete(int $id): bool;

    public function getBySale(int $saleId): Collection;
}
