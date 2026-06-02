<?php

namespace App\Services;

use App\Models\Expense;
use App\Repositories\Contracts\ExpenseRepositoryInterface;
use App\Services\Contracts\ExpenseServiceInterface;
use Illuminate\Database\Eloquent\Collection;

class ExpenseService implements ExpenseServiceInterface
{
    public function __construct(
        protected ExpenseRepositoryInterface $expenseRepository,
    ) {}

    public function getAll(int $businessId): Collection
    {
        return $this->expenseRepository->all($businessId);
    }

    public function getById(int $id): ?Expense
    {
        return $this->expenseRepository->find($id);
    }

    public function create(int $businessId, array $data): Expense
    {
        $data['business_id'] = $businessId;
        return $this->expenseRepository->create($data);
    }

    public function update(int $id, array $data): Expense
    {
        $expense = $this->expenseRepository->find($id);
        if (!$expense) {
            throw new \RuntimeException('Expense not found');
        }
        return $this->expenseRepository->update($expense, $data);
    }

    public function delete(int $id): bool
    {
        $expense = $this->expenseRepository->find($id);
        if (!$expense) {
            throw new \RuntimeException('Expense not found');
        }
        return $this->expenseRepository->delete($expense);
    }

    public function getByDateRange(int $businessId, string $start, string $end): Collection
    {
        return $this->expenseRepository->getByDateRange($businessId, $start, $end);
    }

    public function getByCategory(int $businessId, int $categoryId): Collection
    {
        return $this->expenseRepository->getByCategory($businessId, $categoryId);
    }
}
