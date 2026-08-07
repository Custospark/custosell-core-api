<?php

namespace App\Repositories\Eloquent;

use App\Models\Expense;
use App\Models\IncomeSource;
use App\Models\PersonalBudget;
use App\Repositories\Contracts\PersonalBudgetRepositoryInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;

class PersonalBudgetRepository implements PersonalBudgetRepositoryInterface
{
    public function all(int $businessId, array $filters = []): Collection
    {
        $query = PersonalBudget::where('business_id', $businessId)
            ->withCount([
                'linkedExpenses',
                'linkedIncome',
            ]);

        if (!empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        return $query->orderByDesc('created_at')->orderByDesc('id')->get();
    }

    public function find(int $id): ?PersonalBudget
    {
        return PersonalBudget::with(['linkedExpenses', 'linkedIncome'])->find($id);
    }

    public function create(array $data): PersonalBudget
    {
        return PersonalBudget::create($data);
    }

    public function update(PersonalBudget $budget, array $data): PersonalBudget
    {
        $budget->update($data);
        return $budget->fresh();
    }

    public function delete(PersonalBudget $budget): bool
    {
        // Unlink expense/income records first so records survive budget deletion.
        Expense::where('budget_id', $budget->id)->update(['budget_id' => null]);
        IncomeSource::where('budget_id', $budget->id)->update(['budget_id' => null]);
        return $budget->delete();
    }

    public function summarise(int $businessId, array $filters = []): array
    {
        $query = PersonalBudget::where('business_id', $businessId);
        if (!empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }
        $budgets = $query->get();

        $spend = collect();
        $income = collect();

        if ($budgets->isNotEmpty()) {
            $ids = $budgets->pluck('id');

            if (!empty($filters['date_from']) && !empty($filters['date_to'])) {
                $spend = Expense::whereIn('budget_id', $ids)
                    ->whereBetween('expense_date', [$filters['date_from'], $filters['date_to']])
                    ->selectRaw('budget_id, COALESCE(SUM(amount),0) as total')
                    ->groupBy('budget_id')
                    ->pluck('total', 'budget_id');

                $income = IncomeSource::whereIn('budget_id', $ids)
                    ->whereBetween('income_date', [$filters['date_from'], $filters['date_to']])
                    ->selectRaw('budget_id, COALESCE(SUM(amount),0) as total')
                    ->groupBy('budget_id')
                    ->pluck('total', 'budget_id');
            } else {
                $spend = Expense::whereIn('budget_id', $ids)
                    ->selectRaw('budget_id, COALESCE(SUM(amount),0) as total')
                    ->groupBy('budget_id')
                    ->pluck('total', 'budget_id');

                $income = IncomeSource::whereIn('budget_id', $ids)
                    ->selectRaw('budget_id, COALESCE(SUM(amount),0) as total')
                    ->groupBy('budget_id')
                    ->pluck('total', 'budget_id');
            }
        }

        $rows = $budgets->map(function (PersonalBudget $b) use ($spend, $income, $filters) {
            $actualSpend = (float) ($spend->get($b->id) ?? 0);
            $actualIncome = (float) ($income->get($b->id) ?? 0);
            $planned = (float) $b->planned_amount;
            $remaining = $planned - $actualSpend;
            $percentage = $planned > 0 ? round(($actualSpend / $planned) * 100, 1) : 0;
            return [
                'id' => $b->id,
                'name' => $b->name,
                'description' => $b->description,
                'planned_amount' => $planned,
                'period_start' => $b->period_start?->toDateString(),
                'period_end' => $b->period_end?->toDateString(),
                'status' => $b->status,
                'actual_income' => round($actualIncome, 2),
                'actual_spend' => round($actualSpend, 2),
                'remaining' => round($remaining, 2),
                'percentage' => $percentage,
                'expense_count' => $b->linked_expenses_count,
                'income_count' => $b->linked_income_count,
                'pacing' => $this->buildPacing($b, $filters),
            ];
        })->values();

        return [
            'budgets' => $rows,
            'total_planned' => round($rows->sum('planned_amount'), 2),
            'total_spend' => round($rows->sum('actual_spend'), 2),
            'total_income' => round($rows->sum('actual_income'), 2),
        ];
    }

    /**
     * Cumulative spend-vs-budget pacing for a budget. The budget line spreads
     * the planned amount evenly across the coverage period; actual accumulates
     * daily. Empty when the budget has no coverage dates.
     */
    protected function buildPacing(PersonalBudget $budget, array $filters): array
    {
        $start = $budget->period_start;
        $end = $budget->period_end;
        $planned = (float) $budget->planned_amount;
        if (!$start || !$end || $planned <= 0) {
            return [];
        }

        $startDate = $start->toDateString();
        $endDate = $end->toDateString();
        $days = (int) $start->diffInDays($end) + 1;
        if ($days <= 0) {
            return [];
        }

        $daily = Expense::where('budget_id', $budget->id)
            ->whereBetween('expense_date', [$startDate, $endDate])
            ->selectRaw('DATE(expense_date) as d, SUM(amount) as total')
            ->groupBy('d')
            ->pluck('total', 'd');

        $budgetPerDay = $planned / $days;
        $cumulativeSpend = 0;
        $series = [];

        $date = $budget->period_start->copy();
        while ($date->lte($budget->period_end)) {
            $key = $date->toDateString();
            $cumulativeSpend += (float) ($daily[$key] ?? 0);
            $daysElapsed = (int) $start->diffInDays($date) + 1;
            $series[] = [
                'label' => $date->format('d/m'),
                'budget' => round($budgetPerDay * $daysElapsed, 2),
                'actual' => round($cumulativeSpend, 2),
            ];
            $date->addDay();
        }

        return $series;
    }
}