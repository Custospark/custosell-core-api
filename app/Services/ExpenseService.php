<?php

namespace App\Services;

use App\Models\Expense;
use App\Repositories\Contracts\ExpenseCategoryRepositoryInterface;
use App\Repositories\Contracts\ExpenseRepositoryInterface;
use App\Models\IncomeSource;
use App\Repositories\Contracts\IncomeSourceRepositoryInterface;
use App\Services\Contracts\ExpenseServiceInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
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
        protected IncomeSourceRepositoryInterface $incomeSourceRepository,
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

    public function getOverview(int $businessId, array $filters = []): array
    {
        $dateFrom = $filters['date_from'] ?? now()->startOfMonth()->toDateString();
        $dateTo = $filters['date_to'] ?? now()->endOfMonth()->toDateString();

        $incomeSummary = $this->incomeSourceRepository->getSummary($businessId, [
            'date_from' => $dateFrom,
            'date_to' => $dateTo,
        ]);

        $expenseSummary = $this->expenseRepository->getSummary($businessId, [
            'date_from' => $dateFrom,
            'date_to' => $dateTo,
        ]);

        $totalIncome = $incomeSummary['total_amount'];
        $totalExpenses = $expenseSummary['total_amount'];
        $netBalance = $totalIncome - $totalExpenses;

        $monthlyTrends = $this->buildMonthlyTrends($businessId, $dateFrom, $dateTo);

        $recentIncome = IncomeSource::where('business_id', $businessId)
            ->orderBy('income_date', 'desc')
            ->take(5)
            ->get()
            ->map(fn($i) => [
                'type' => 'income',
                'amount' => (float) $i->amount,
                'description' => $i->source_name . ($i->description ? ' — ' . $i->description : ''),
                'date' => $i->income_date->toISOString(),
                'id' => $i->id,
            ]);

        $recentExpenses = $this->expenseRepository->getByDateRange($businessId, $dateFrom, $dateTo)
            ->take(5)
            ->map(fn($e) => [
                'type' => 'expense',
                'amount' => (float) $e->amount,
                'description' => ($e->expenseCategory?->name ?? 'Uncategorized') . ($e->description ? ' — ' . $e->description : ''),
                'date' => $e->expense_date?->toISOString(),
                'id' => $e->id,
            ]);

        $recentTransactions = collect($recentIncome)
            ->concat($recentExpenses)
            ->sortByDesc('date')
            ->take(10)
            ->values();

        return [
            'total_income' => $totalIncome,
            'total_expenses' => $totalExpenses,
            'net_balance' => $netBalance,
            'income_count' => $incomeSummary['total_count'],
            'expense_count' => $expenseSummary['total_count'],
            'income_by_source' => $incomeSummary['by_source'] ?? [],
            'expenses_by_category' => $expenseSummary['by_category'] ?? [],
            'monthly_trends' => $monthlyTrends,
            'recent_transactions' => $recentTransactions,
        ];
    }

    protected function buildMonthlyTrends(int $businessId, string $dateFrom, string $dateTo): array
    {
        $incomeTrends = IncomeSource::where('business_id', $businessId)
            ->whereBetween('income_date', [$dateFrom, $dateTo])
            ->selectRaw("DATE_FORMAT(income_date, '%Y-%m') as month, SUM(amount) as total")
            ->groupBy('month')
            ->orderBy('month')
            ->pluck('total', 'month');

        $expenseTrends = \App\Models\Expense::where('business_id', $businessId)
            ->whereBetween('expense_date', [$dateFrom, $dateTo])
            ->selectRaw("DATE_FORMAT(expense_date, '%Y-%m') as month, SUM(amount) as total")
            ->groupBy('month')
            ->orderBy('month')
            ->pluck('total', 'month');

        $months = collect(array_unique(array_merge(
            $incomeTrends->keys()->toArray(),
            $expenseTrends->keys()->toArray(),
        )))->sort();

        return $months->map(fn($month) => [
            'month' => $month,
            'income' => (float) ($incomeTrends[$month] ?? 0),
            'expenses' => (float) ($expenseTrends[$month] ?? 0),
        ])->values()->toArray();
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
