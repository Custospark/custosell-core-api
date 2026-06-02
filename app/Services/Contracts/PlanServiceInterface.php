<?php

namespace App\Services\Contracts;

use App\Models\Plan;
use Illuminate\Database\Eloquent\Collection;

interface PlanServiceInterface
{
    public function getAll(): Collection;

    public function getById(int $id): ?Plan;

    public function getActive(): Collection;

    public function create(array $data): Plan;

    public function update(int $id, array $data): Plan;

    public function delete(int $id): bool;
}
