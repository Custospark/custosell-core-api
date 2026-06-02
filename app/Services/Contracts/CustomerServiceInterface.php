<?php

namespace App\Services\Contracts;

use App\Models\Customer;
use Illuminate\Database\Eloquent\Collection;

interface CustomerServiceInterface
{
    public function getAll(int $businessId): Collection;

    public function getById(int $id): ?Customer;

    public function create(int $businessId, array $data): Customer;

    public function update(int $id, array $data): Customer;

    public function delete(int $id): bool;
}
