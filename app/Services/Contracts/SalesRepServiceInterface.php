<?php

namespace App\Services\Contracts;

use App\Models\SalesRep;
use Illuminate\Database\Eloquent\Collection;

interface SalesRepServiceInterface
{
    public function getAll(): Collection;
    public function getById(int $id): ?SalesRep;
    public function getByUser(int $userId): ?SalesRep;
    public function create(array $data): SalesRep;
    public function update(int $id, array $data): SalesRep;
    public function delete(int $id): bool;
    public function getActive(): Collection;
}
