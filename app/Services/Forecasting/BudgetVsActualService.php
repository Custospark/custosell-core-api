<?php

declare(strict_types=1);

namespace App\Services\Forecasting;

use App\Models\AccountingPeriod;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use Carbon\Carbon;
use Illuminate\Validation\ValidationException;

class BudgetVsActualService
{
    /**
     * @return array<string, mixed>
     */
    public function analyze(
        int $businessId,
        ?int $periodId = null,
        ?string $startDate = null,
        ?string $endDate = null,
    ): array {
        [$start, $end, $periodMeta] = $this->resolveRange($businessId, $periodId, $startDate, $endDate);

        $categories = ExpenseCategory::query()
            ->where('business_id', $businessId)
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();

        $actualsByCategory = Expense::query()
            ->where('business_id', $businessId)
            ->whereBetween('expense_date', [$start->copy()->startOfDay(), $end->copy()->endOfDay()])
            ->selectRaw('expense_category_id, SUM(amount) as total')
            ->groupBy('expense_category_id')
            ->pluck('total', 'expense_category_id');

        $assumptions = [
            'Category budgets are normalized to the selected period length from budget_period (weekly/monthly/quarterly/yearly).',
            'Actuals are summed from Expense.amount within the period by expense_category_id.',
        ];
        $warnings = [];

        $rows = [];
        $totalBudget = 0.0;
        $totalActual = 0.0;

        foreach ($categories as $category) {
            $rawBudget = $category->budget_amount !== null ? (float) $category->budget_amount : null;
            $normalizedBudget = $rawBudget !== null
                ? $this->normalizeBudget($rawBudget, $category->budget_period, $start, $end)
                : 0.0;

            if ($rawBudget === null) {
                $warnings[] = 'Category "'.$category->name.'" has no budget_amount; treated as 0.';
            }

            $actual = round((float) ($actualsByCategory[$category->id] ?? 0), 2);
            $variance = round($actual - $normalizedBudget, 2);
            $variancePct = $normalizedBudget > 0
                ? round(($variance / $normalizedBudget) * 100, 2)
                : ($actual > 0 ? null : 0.0);

            $totalBudget += $normalizedBudget;
            $totalActual += $actual;

            $rows[] = [
                'expense_category_id' => $category->id,
                'name' => $category->name,
                'slug' => $category->slug,
                'budget_period' => $category->budget_period,
                'raw_budget_amount' => $rawBudget,
                'budget' => $normalizedBudget,
                'actual' => $actual,
                'variance' => $variance,
                'variance_pct' => $variancePct,
                'status' => $this->varianceStatus($variance, $normalizedBudget),
            ];
        }

        $totalVariance = round($totalActual - $totalBudget, 2);

        return [
            'period' => $periodMeta,
            'start_date' => $start->toDateString(),
            'end_date' => $end->toDateString(),
            'categories' => $rows,
            'totals' => [
                'budget' => round($totalBudget, 2),
                'actual' => round($totalActual, 2),
                'variance' => $totalVariance,
                'variance_pct' => $totalBudget > 0
                    ? round(($totalVariance / $totalBudget) * 100, 2)
                    : ($totalActual > 0 ? null : 0.0),
            ],
            'assumptions' => $assumptions,
            'warnings' => array_values(array_unique($warnings)),
        ];
    }

    /**
     * @return array{0: Carbon, 1: Carbon, 2: array<string, mixed>|null}
     */
    protected function resolveRange(
        int $businessId,
        ?int $periodId,
        ?string $startDate,
        ?string $endDate,
    ): array {
        if ($periodId !== null) {
            $period = AccountingPeriod::query()
                ->where('business_id', $businessId)
                ->whereKey($periodId)
                ->first();

            if (! $period) {
                throw ValidationException::withMessages([
                    'period_id' => 'Accounting period not found for this business.',
                ]);
            }

            return [
                Carbon::parse($period->start_date)->startOfDay(),
                Carbon::parse($period->end_date)->endOfDay(),
                [
                    'id' => $period->id,
                    'name' => $period->name,
                    'start_date' => $period->start_date->toDateString(),
                    'end_date' => $period->end_date->toDateString(),
                    'is_closed' => (bool) $period->is_closed,
                ],
            ];
        }

        if ($startDate && $endDate) {
            $start = Carbon::parse($startDate)->startOfDay();
            $end = Carbon::parse($endDate)->endOfDay();
            if ($end->lt($start)) {
                throw ValidationException::withMessages([
                    'end_date' => 'end_date must be on or after start_date.',
                ]);
            }

            return [$start, $end, null];
        }

        $start = now()->startOfMonth()->startOfDay();
        $end = now()->endOfMonth()->endOfDay();

        return [$start, $end, null];
    }

    protected function normalizeBudget(float $amount, ?string $budgetPeriod, Carbon $start, Carbon $end): float
    {
        $days = max(1, (int) $start->diffInDays($end) + 1);
        $period = $budgetPeriod ?: 'monthly';

        $daily = match ($period) {
            'weekly' => $amount / 7,
            'monthly' => $amount / 30.4375,
            'quarterly' => $amount / 91.3125,
            'yearly' => $amount / 365.25,
            default => $amount / 30.4375,
        };

        return round($daily * $days, 2);
    }

    protected function varianceStatus(float $variance, float $budget): string
    {
        if ($budget <= 0) {
            return $variance > 0 ? 'over' : 'on_track';
        }

        $pct = ($variance / $budget) * 100;
        if ($pct > 5) {
            return 'over';
        }
        if ($pct < -5) {
            return 'under';
        }

        return 'on_track';
    }
}
