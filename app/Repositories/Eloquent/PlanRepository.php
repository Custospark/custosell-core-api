<?php

namespace App\Repositories\Eloquent;

use App\Models\Plan;
use App\Repositories\Contracts\PlanRepositoryInterface;
use Illuminate\Database\Eloquent\Collection;

class PlanRepository implements PlanRepositoryInterface
{
    public function all(): Collection
    {
        return Plan::orderBy('sort_order')->get();
    }

    public function find(int $id): ?Plan
    {
        return Plan::find($id);
    }

    public function findBySlug(string $slug): ?Plan
    {
        return Plan::where('slug', $slug)->first();
    }

    public function create(array $data): Plan
    {
        return Plan::create($data);
    }

    public function update(Plan $plan, array $data): Plan
    {
        $plan->update($data);
        return $plan->fresh();
    }

    public function delete(Plan $plan): bool
    {
        return $plan->delete();
    }

    public function getActive(): Collection
    {
        return Plan::where('is_active', true)->orderBy('sort_order')->get();
    }
}
