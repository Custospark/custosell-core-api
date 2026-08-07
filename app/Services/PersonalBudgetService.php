<?php

namespace App\Services;

use App\Models\PersonalBudget;
use App\Repositories\Contracts\PersonalBudgetRepositoryInterface;
use App\Services\Contracts\PersonalBudgetServiceInterface;

class PersonalBudgetService implements PersonalBudgetServiceInterface
{
    public function __construct(
        protected PersonalBudgetRepositoryInterface $personalBudgetRepository,
    ) {}

    public function getAll(int $businessId, array $filters = []): array
    {
        return $this->personalBudgetRepository->summarise($businessId, $filters);
    }

    public function getById(int $id): ?PersonalBudget
    {
        return $this->personalBudgetRepository->find($id);
    }

    public function create(int $businessId, int $userId, array $data): PersonalBudget
    {
        $data['business_id'] = $businessId;
        $data['user_id'] = $userId;
        $data['status'] = $data['status'] ?? 'active';
        return $this->personalBudgetRepository->create($data);
    }

    public function update(int $id, array $data): PersonalBudget
    {
        $budget = $this->personalBudgetRepository->find($id);
        if (!$budget) {
            throw new \RuntimeException('Budget not found');
        }
        return $this->personalBudgetRepository->update($budget, $data);
    }

    public function delete(int $id): bool
    {
        $budget = $this->personalBudgetRepository->find($id);
        if (!$budget) {
            throw new \RuntimeException('Budget not found');
        }
        return $this->personalBudgetRepository->delete($budget);
    }
}