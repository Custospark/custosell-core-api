<?php

declare(strict_types=1);

namespace App\Services\Assistant\Tools;

use App\Models\User;
use App\Services\Contracts\ExpenseServiceInterface;
use App\Services\ModuleAccessService;

class ExpenseSummaryTool extends AbstractAssistantTool
{
    public function __construct(
        ModuleAccessService $moduleAccess,
        private ExpenseServiceInterface $expenses,
    ) {
        parent::__construct($moduleAccess);
    }

    public function name(): string
    {
        return 'expense_summary';
    }

    public function description(): string
    {
        return 'Expense totals over recent days with the top categories. No attachments or notes.';
    }

    public function parameters(): array
    {
        return [
            'days' => ['type' => 'int', 'required' => false, 'min' => 1, 'max' => 90, 'default' => 30],
        ];
    }

    public function requiredModule(): string
    {
        return 'expenses';
    }

    public function endpoint(): string
    {
        return 'GET /money/summary';
    }

    public function execute(User $user, array $args): array
    {
        if ($denied = $this->denied($user)) {
            return $denied;
        }

        $days = $this->argInt($args, 'days', 30, 1, 90);
        $start = now()->subDays($days)->startOfDay()->toDateTimeString();
        $end = now()->endOfDay()->toDateTimeString();
        $expenses = $this->expenses->getByDateRange($this->businessId($user), $start, $end);

        $total = 0.0;
        $byCategory = [];
        foreach ($expenses as $expense) {
            $amount = (float) $expense->amount;
            $total += $amount;
            $label = $expense->category?->name ?? 'Uncategorized';
            $byCategory[$label] = round(($byCategory[$label] ?? 0) + $amount, 2);
        }
        arsort($byCategory);

        $this->audit($user, "days={$days}");

        return [
            'days' => $days,
            'count' => $expenses->count(),
            'total' => round($total, 2),
            'top_categories' => array_slice($byCategory, 0, 5, true),
        ];
    }
}
