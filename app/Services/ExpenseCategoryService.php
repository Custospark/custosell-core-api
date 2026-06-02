<?php

namespace App\Services;

use App\Models\ExpenseCategory;
use App\Repositories\Contracts\ExpenseCategoryRepositoryInterface;
use App\Services\Contracts\ExpenseCategoryServiceInterface;
use Illuminate\Database\Eloquent\Collection;

class ExpenseCategoryService implements ExpenseCategoryServiceInterface
{
    public function __construct(
        protected ExpenseCategoryRepositoryInterface $expenseCategoryRepository,
    ) {}

    public function getAll(int $businessId): Collection
    {
        return $this->expenseCategoryRepository->all($businessId);
    }

    public function getById(int $id): ?ExpenseCategory
    {
        return $this->expenseCategoryRepository->find($id);
    }

    public function create(int $businessId, array $data): ExpenseCategory
    {
        $data['business_id'] = $businessId;
        return $this->expenseCategoryRepository->create($data);
    }

    public function update(int $id, array $data): ExpenseCategory
    {
        $expenseCategory = $this->expenseCategoryRepository->find($id);
        if (!$expenseCategory) {
            throw new \RuntimeException('Expense category not found');
        }
        return $this->expenseCategoryRepository->update($expenseCategory, $data);
    }

    public function delete(int $id): bool
    {
        $expenseCategory = $this->expenseCategoryRepository->find($id);
        if (!$expenseCategory) {
            throw new \RuntimeException('Expense category not found');
        }
        return $this->expenseCategoryRepository->delete($expenseCategory);
    }
}
