<?php

namespace App\Repositories\Contracts;

use App\Models\Plan;
use Illuminate\Database\Eloquent\Collection;

interface PlanRepositoryInterface
{
    public function all(): Collection;

    public function find(int $id): ?Plan;

    public function findBySlug(string $slug): ?Plan;

    public function create(array $data): Plan;

    public function update(Plan $plan, array $data): Plan;

    public function delete(Plan $plan): bool;

    public function getActive(): Collection;
}
