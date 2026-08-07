<?php

namespace App\Services;

use App\Models\Expense;
use App\Repositories\Contracts\ExpenseCategoryRepositoryInterface;
use App\Repositories\Contracts\ExpenseRepositoryInterface;
use App\Models\Business;
use App\Models\ExpenseCategory;
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
        $this->assertLocationBelongsToBusiness($businessId, $data['location_id'] ?? null);

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

        if (array_key_exists('location_id', $data)) {
            $this->assertLocationBelongsToBusiness($expense->business_id, $data['location_id']);
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

    public function getOverview(int $businessId, array $filters = [], ?string $accountType = null): array
    {
        $dateFrom = $filters['date_from'] ?? now()->startOfMonth()->toDateString();
        $dateTo = $filters['date_to'] ?? now()->endOfMonth()->toDateString();
        $locationId = $filters['location_id'] ?? null;

        $isPersonal = $accountType === 'personal';

        $incomeSummary = $isPersonal
            ? $this->incomeSourceRepository->getSummary($businessId, [
                'date_from' => $dateFrom,
                'date_to' => $dateTo,
            ])
            : ['total_amount' => 0, 'total_count' => 0, 'by_source' => []];

        $expenseSummary = $this->expenseRepository->getSummary($businessId, [
            'date_from' => $dateFrom,
            'date_to' => $dateTo,
            'location_id' => $locationId,
        ]);

        $totalIncome = $incomeSummary['total_amount'];
        $totalExpenses = $expenseSummary['total_amount'];
        $netBalance = $totalIncome - $totalExpenses;

        $monthlyTrends = $this->buildYearlyMonthlyTrends($businessId, $isPersonal);

        return [
            'account_type' => $isPersonal ? 'personal' : 'business',
            'total_income' => $totalIncome,
            'total_expenses' => $totalExpenses,
            'net_balance' => $totalExpenses === 0 ? $totalIncome : $netBalance,
            'income_count' => $incomeSummary['total_count'],
            'expense_count' => $expenseSummary['total_count'],
            'income_by_source' => $incomeSummary['by_source'] ?? [],
            'expenses_by_category' => $expenseSummary['by_category'] ?? [],
            'monthly_trends' => $monthlyTrends,
            'daily_spending_trends' => $this->buildDailySpendingTrends($businessId, $dateFrom, $locationId),
            'monthly_spending_trends' => $this->buildMonthlySpendingTrends($businessId, $dateFrom, $locationId),
            'recent_transactions' => $this->buildRecentTransactions($businessId, $dateFrom, $dateTo, $isPersonal, $locationId),
        ];
    }

    /** Per-day-of-month expense totals within the month of $dateFrom, filled as a line/bar series. */
    protected function buildDailySpendingTrends(int $businessId, string $dateFrom, ?int $locationId = null): array
    {
        $monthKey = \Illuminate\Support\Carbon::parse($dateFrom)->format('Y-m');
        $daysInMonth = \Illuminate\Support\Carbon::parse($dateFrom)->daysInMonth;
        $month = \Illuminate\Support\Carbon::parse($dateFrom)->month;
        $first = date('Y-m-d', strtotime($monthKey . '-01'));
        $last = date('Y-m-t', strtotime($monthKey . '-01'));

        $query = DB::table('expenses')
            ->where('business_id', $businessId)
            ->whereBetween('expense_date', [$first, $last]);
        if ($locationId) {
            $query->where('location_id', $locationId);
        }
        $rows = $query
            ->selectRaw('DAY(expense_date) as d, SUM(amount) as total')
            ->groupBy('d')
            ->pluck('total', 'd');

        $series = [];
        for ($day = 1; $day <= $daysInMonth; $day++) {
            $series[] = [
                'day' => $day,
                'label' => sprintf('%02d/%02d', $day, $month),
                'expenses' => round((float) ($rows[$day] ?? 0), 2),
            ];
        }

        return $series;
    }

    /** Per-month-of-year expense totals for the year of $dateFrom, filled for a full year series. */
    protected function buildMonthlySpendingTrends(int $businessId, string $dateFrom, ?int $locationId = null): array
    {
        $year = \Illuminate\Support\Carbon::parse($dateFrom)->year;
        $query = DB::table('expenses')
            ->where('business_id', $businessId)
            ->whereYear('expense_date', $year);
        if ($locationId) {
            $query->where('location_id', $locationId);
        }
        $rows = $query
            ->selectRaw('MONTH(expense_date) as m, SUM(amount) as total')
            ->groupBy('m')
            ->pluck('total', 'm');

        $labels = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
        $series = [];
        for ($m = 1; $m <= 12; $m++) {
            $series[] = [
                'month' => $m,
                'label' => $labels[$m - 1],
                'expenses' => round((float) ($rows[$m] ?? 0), 2),
            ];
        }

        return $series;
    }

    /** Merged recent income/expense entries. Income excluded for business accounts. */
    protected function buildRecentTransactions(int $businessId, string $dateFrom, string $dateTo, bool $isPersonal, ?int $locationId = null): array
    {
        $recentIncome = collect();
        if ($isPersonal) {
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
        }

        $recentExpenses = $this->expenseRepository->getByDateRange($businessId, $dateFrom, $dateTo, $locationId ?? null)
            ->take(5)
            ->map(fn($e) => [
                'type' => 'expense',
                'amount' => (float) $e->amount,
                'description' => ($e->expenseCategory?->name ?? 'Uncategorized') . ($e->description ? ' — ' . $e->description : ''),
                'date' => $e->expense_date?->toISOString(),
                'id' => $e->id,
            ]);

        return collect($recentIncome)
            ->concat($recentExpenses)
            ->sortByDesc('date')
            ->take(10)
            ->values()
            ->all();
    }

    public function getBudgets(int $businessId, array $filters = []): array
    {
        $dateFrom = $filters['date_from'] ?? now()->startOfMonth()->toDateString();
        $dateTo = $filters['date_to'] ?? now()->endOfMonth()->toDateString();

        $business = Business::find($businessId);
        $incomeTarget = (float) ($business?->income_target ?? 0);

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

        $categoryBudgets = ExpenseCategory::where('business_id', $businessId)
            ->whereNotNull('budget_amount')
            ->get();

        $expensesByCategory = collect($expenseSummary['by_category'] ?? [])
            ->keyBy('category_id');

        $categories = $categoryBudgets->map(function ($cat) use ($expensesByCategory) {
            $actual = (float) ($expensesByCategory->get($cat->id)['total'] ?? 0);
            $budget = (float) $cat->budget_amount;
            $remaining = $budget - $actual;
            $percentage = $budget > 0 ? round(($actual / $budget) * 100, 1) : 0;
            return [
                'id' => $cat->id,
                'name' => $cat->name,
                'budget' => $budget,
                'actual' => $actual,
                'remaining' => $remaining,
                'percentage' => $percentage,
            ];
        })->values();

        $totalExpenseBudget = $categoryBudgets->sum('budget_amount');
        $expenseBudgetActual = $categories->sum('actual');
        $netTarget = $incomeTarget - (float) $totalExpenseBudget;
        $netActual = $totalIncome - $totalExpenses;

        $periodStart = \Carbon\Carbon::parse($dateFrom);
        $periodEnd = \Carbon\Carbon::parse($dateTo);
        $daysTotal = $periodStart->diffInDays($periodEnd) + 1;
        $daysRemaining = max(0, now()->diffInDays($periodEnd, false));
        $remainingBudget = $totalExpenseBudget - $expenseBudgetActual;
        $dailyRemaining = $daysRemaining > 0 ? round($remainingBudget / $daysRemaining, 2) : 0;

        $monthLabels = [
            1 => 'January', 2 => 'February', 3 => 'March', 4 => 'April',
            5 => 'May', 6 => 'June', 7 => 'July', 8 => 'August',
            9 => 'September', 10 => 'October', 11 => 'November', 12 => 'December',
        ];

        return [
            'period' => [
                'start' => $dateFrom,
                'end' => $dateTo,
                'days_remaining' => $daysRemaining,
                'label' => $monthLabels[(int) $periodStart->format('n')] . ' ' . $periodStart->format('Y'),
            ],
            'income_target' => $incomeTarget,
            'income_actual' => $totalIncome,
            'expense_budget' => (float) $totalExpenseBudget,
            'expense_actual' => $totalExpenses,
            'net_target' => $netTarget,
            'net_actual' => $netActual,
            'daily_remaining' => $dailyRemaining,
            'categories' => $categories,
        ];
    }

    /** Full calendar year income/expense trend, filled for all 12 months. */
    protected function buildYearlyMonthlyTrends(int $businessId, bool $isPersonal = true): array
    {
        $year = now()->year;

        $incomeTrends = $isPersonal
            ? IncomeSource::where('business_id', $businessId)
                ->whereYear('income_date', $year)
                ->selectRaw("MONTH(income_date) as m, SUM(amount) as total")
                ->groupBy('m')
                ->pluck('total', 'm')
            : collect();

        $expenseTrends = \App\Models\Expense::where('business_id', $businessId)
            ->whereYear('expense_date', $year)
            ->selectRaw("MONTH(expense_date) as m, SUM(amount) as total")
            ->groupBy('m')
            ->pluck('total', 'm');

        $labels = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];

        $series = [];
        for ($m = 1; $m <= 12; $m++) {
            $series[] = [
                'month' => $m,
                'label' => $labels[$m - 1],
                'income' => $isPersonal ? (float) ($incomeTrends[$m] ?? 0) : 0,
                'expenses' => (float) ($expenseTrends[$m] ?? 0),
            ];
        }

        return $series;
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

    protected function assertLocationBelongsToBusiness(int $businessId, mixed $locationId): void
    {
        if ($locationId === null || $locationId === '') {
            return;
        }

        $location = \App\Models\Location::query()
            ->where('id', (int) $locationId)
            ->where('business_id', $businessId)
            ->first();

        if (!$location) {
            throw ValidationException::withMessages([
                'location_id' => 'Invalid branch for this business.',
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
