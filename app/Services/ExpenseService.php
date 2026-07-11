<?php

namespace App\Services;

use App\Models\Expense;
use App\Repositories\Contracts\ExpenseCategoryRepositoryInterface;
use App\Repositories\Contracts\ExpenseRepositoryInterface;
use App\Services\Contracts\ExpenseServiceInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Validation\ValidationException;

use App\Events\ExpenseCreatedForAccounting;
use App\Models\FixedAsset;
use App\Models\ProjectCostAllocation;
use App\Services\Contracts\ProjectServiceInterface;

class ExpenseService implements ExpenseServiceInterface
{
    public function __construct(
        protected ExpenseRepositoryInterface $expenseRepository,
        protected ExpenseCategoryRepositoryInterface $expenseCategoryRepository,
        protected ProjectServiceInterface $projectService,
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
        $this->assertFixedAssetBelongsToBusiness($businessId, $data['fixed_asset_id'] ?? null);

        $expense = $this->expenseRepository->create($data);

        if (!empty($data['project_id'])) {
            $this->createProjectAllocationFromExpense($expense);
        }

        event(new ExpenseCreatedForAccounting($expense));

        return $expense;
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

        if (array_key_exists('fixed_asset_id', $data)) {
            $this->assertFixedAssetBelongsToBusiness($expense->business_id, $data['fixed_asset_id']);
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

    protected function assertFixedAssetBelongsToBusiness(int $businessId, mixed $fixedAssetId): void
    {
        if ($fixedAssetId === null || $fixedAssetId === '') {
            return;
        }

        $asset = FixedAsset::query()
            ->where('id', (int) $fixedAssetId)
            ->where('business_id', $businessId)
            ->first();

        if (!$asset) {
            throw ValidationException::withMessages([
                'fixed_asset_id' => 'Invalid fixed asset for this business.',
            ]);
        }
    }

    protected function createProjectAllocationFromExpense(Expense $expense): void
    {
        $typeMap = [
            'labor' => 'labor',
            'material' => 'material',
        ];

        $categorySlug = $expense->expenseCategory?->slug;
        $allocationType = $typeMap[$categorySlug] ?? 'expense';

        ProjectCostAllocation::create([
            'business_id' => $expense->business_id,
            'project_id' => $expense->project_id,
            'allocation_type' => $allocationType,
            'description' => $expense->description ?: 'Allocated from expense',
            'amount' => (float) $expense->amount,
            'basis' => 'fixed',
            'basis_value' => 0,
            'allocation_date' => $expense->expense_date?->toDateString() ?? now()->toDateString(),
            'expense_id' => $expense->id,
            'created_by' => $expense->recorded_by ?? 1,
        ]);

        $this->projectService->recalculateActuals($expense->project_id);
    }
}
