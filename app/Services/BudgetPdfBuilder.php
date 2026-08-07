<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Business;
use App\Models\Expense;
use App\Models\IncomeSource;
use App\Models\PersonalBudget;
use App\Models\User;

class BudgetPdfBuilder
{
    public function __construct(
        protected ReportExportService $export,
    ) {}

    /**
     * @return array{view: string, data: array<string, mixed>, filename: string, orientation: string}
     */
    public function build(PersonalBudget $budget, Business $business, User $user): array
    {
        $lines = $budget->lines()->get();
        $expenses = Expense::where('budget_id', $budget->id)->orderByDesc('expense_date')->get();
        $income = IncomeSource::where('budget_id', $budget->id)->orderByDesc('income_date')->get();

        $currency = $business->currency ?? 'UGX';
        $planned = (float) $budget->planned_amount;
        $spent = (float) $expenses->sum('amount');
        $incomeTotal = (float) $income->sum('amount');
        $remaining = $planned - $spent;
        $percentage = $planned > 0 ? round(($spent / $planned) * 100, 1) : 0;

        $isPersonal = $user->account_type === 'personal';
        $headerBusiness = $business;
        $headerName = $business->name ?: $business->slug ?: 'Business';

        if ($isPersonal && $user->name) {
            $headerBusiness = $business->replicate();
            $headerBusiness->name = $user->name;
            $headerName = $user->name;
        }

        $filename = $this->export->buildFilename($headerBusiness, 'budget-' . $this->export->sanitizeFilenamePart($budget->name ?: (string) $budget->id));

        $periodLabel = ($budget->period_start?->format('M d, Y') ?? '…') . ' — ' . ($budget->period_end?->format('M d, Y') ?? '…');

        return [
            'view' => 'budgets.pdf',
            'data' => [
                'business' => $headerBusiness,
                'budget' => $budget,
                'lines' => $lines,
                'expenses' => $expenses,
                'income' => $income,
                'summary' => [
                    'planned' => round($planned, 2),
                    'actual_spend' => round($spent, 2),
                    'actual_income' => round($incomeTotal, 2),
                    'remaining' => round($remaining, 2),
                    'percentage' => $percentage,
                ],
                'currency' => $currency,
                'formatter' => $this->export,
                'reportTitle' => 'Budget',
                'reportSubtitle' => $budget->name . ($budget->period_start || $budget->period_end ? ' · ' . $periodLabel : ''),
                'reportPurpose' => 'A personal plan for how you want to spend and save. Actuals come from income and expenses linked to this budget.',
                'accent' => '#1d4ed8',
                'summaryCards' => [
                    ['label' => 'Planned', 'value' => $this->export->formatMoney($planned, $currency)],
                    ['label' => 'Spent', 'value' => $this->export->formatMoney($spent, $currency)],
                    ['label' => 'Income', 'value' => $this->export->formatMoney($incomeTotal, $currency), 'tone' => 'positive'],
                    ['label' => 'Remaining', 'value' => $this->export->formatMoney($remaining, $currency), 'tone' => $remaining < 0 ? 'negative' : ''],
                ],
            ],
            'filename' => $filename,
            'orientation' => 'portrait',
        ];
    }
}