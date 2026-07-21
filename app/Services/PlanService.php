<?php

namespace App\Services;

use App\Models\Plan;
use App\Repositories\Contracts\PlanRepositoryInterface;
use App\Services\Contracts\PlanServiceInterface;
use Illuminate\Database\Eloquent\Collection;

class PlanService implements PlanServiceInterface
{
    public function __construct(
        protected PlanRepositoryInterface $planRepository,
    ) {}

    public function getAll(): Collection
    {
        return $this->planRepository->all();
    }

    public function getById(int $id): ?Plan
    {
        return $this->planRepository->find($id);
    }

    public function getActive(): Collection
    {
        return $this->planRepository->getActive();
    }

    public function getBySlug(string $slug): ?Plan
    {
        return $this->planRepository->findBySlug($slug);
    }

    public function create(array $data): Plan
    {
        return $this->planRepository->create($data);
    }

    public function update(int $id, array $data): Plan
    {
        $plan = $this->planRepository->find($id);
        if (!$plan) {
            throw new \RuntimeException('Plan not found');
        }
        return $this->planRepository->update($plan, $data);
    }

    public function delete(int $id): bool
    {
        $plan = $this->planRepository->find($id);
        if (!$plan) {
            throw new \RuntimeException('Plan not found');
        }
        return $this->planRepository->delete($plan);
    }
}
