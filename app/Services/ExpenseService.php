<?php

namespace App\Services;

use App\Models\Expense;
use App\Repositories\Contracts\ExpenseCategoryRepositoryInterface;
use App\Repositories\Contracts\ExpenseRepositoryInterface;
use App\Services\Contracts\ExpenseServiceInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Validation\ValidationException;

class ExpenseService implements ExpenseServiceInterface
{
    public function __construct(
        protected ExpenseRepositoryInterface $expenseRepository,
        protected ExpenseCategoryRepositoryInterface $expenseCategoryRepository,
    ) {}

    public function getAll(int $businessId, array $filters = []): LengthAwarePaginator
    {
        return $this->expenseRepository->all($businessId, $filters);
    }

    public function getById(int $id): ?Expense
    {
        return $this->expenseRepository->find($id);
    }

    public function create(int $businessId, array $data): Expense
    {
        $data['business_id'] = $businessId;
        $this->assertCategoryAvailable($businessId, $data['expense_category_id'] ?? null);

        return $this->expenseRepository->create($data);
    }

    public function update(int $id, array $data): Expense
    {
        $expense = $this->expenseRepository->find($id);
        if (!$expense) {
            throw new \RuntimeException('Expense not found');
        }

        if (array_key_exists('expense_category_id', $data)) {
            $this->assertCategoryAvailable($expense->business_id, $data['expense_category_id']);
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

    public function getByShift(int $businessId, int $shiftId): Collection
    {
        return $this->expenseRepository->getByShift($businessId, $shiftId);
    }

    public function getSummary(int $businessId, array $filters = []): array
    {
        return $this->expenseRepository->getSummary($businessId, $filters);
    }

    protected function assertCategoryAvailable(int $businessId, mixed $categoryId): void
    {
        if ($categoryId === null || $categoryId === '') {
            return;
        }

        $category = $this->expenseCategoryRepository->findAvailableForBusiness($businessId, (int) $categoryId);
        if (!$category) {
            throw ValidationException::withMessages([
                'expense_category_id' => 'Invalid expense category.',
            ]);
        }
    }
}
