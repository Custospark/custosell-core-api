<?php

namespace App\Services;

use App\Models\AccountType;
use App\Models\ChartOfAccount;
use Illuminate\Support\Facades\Log;

class FinancialStatementService
{
    public function __construct(
        protected LedgerService $ledgerService,
    ) {}

    public function incomeStatement(int $businessId, int $periodId): array
    {
        $revenueType = AccountType::where('name', 'Revenue')->first();
        $expenseType = AccountType::where('name', 'Expense')->first();

        $revenueAccounts = ChartOfAccount::where('business_id', $businessId)
            ->where('type_id', $revenueType?->id)
            ->where('is_active', true)
            ->get();

        $expenseAccounts = ChartOfAccount::where('business_id', $businessId)
            ->where('type_id', $expenseType?->id)
            ->where('is_active', true)
            ->get();

        $revenues = [];
        $totalRevenue = 0;
        foreach ($revenueAccounts as $account) {
            $balance = $this->ledgerService->calculateAccountBalance($account->id, $businessId, $periodId);
            $revenues[] = [
                'account_code' => $account->code,
                'account_name' => $account->name,
                'balance' => $balance,
            ];
            $totalRevenue += $balance;
        }

        $expenses = [];
        $totalExpenses = 0;
        foreach ($expenseAccounts as $account) {
            $balance = $this->ledgerService->calculateAccountBalance($account->id, $businessId, $periodId);
            $expenses[] = [
                'account_code' => $account->code,
                'account_name' => $account->name,
                'balance' => $balance,
            ];
            $totalExpenses += $balance;
        }

        $cogsAccounts = array_filter($expenses, function ($e) {
            return in_array($e['account_code'], ['5100', '5200', '5300']);
        });
        $cogs = array_sum(array_column($cogsAccounts, 'balance'));

        $taxAccounts = array_filter($expenses, function ($e) {
            return $e['account_code'] === '6500';
        });
        $taxExpense = array_sum(array_column($taxAccounts, 'balance'));

        $grossProfit = $totalRevenue - $cogs;
        $operatingExpenses = $totalExpenses - $cogs;
        $operatingIncome = $grossProfit - $operatingExpenses;

        return [
            'sections' => [
                'revenue' => $revenues,
                'cost_of_goods_sold' => array_values($cogsAccounts),
                'operating_expenses' => $expenses,
            ],
            'total_revenue' => round($totalRevenue, 2),
            'total_cost_of_goods_sold' => round($cogs, 2),
            'gross_profit' => round($grossProfit, 2),
            'total_operating_expenses' => round($operatingExpenses, 2),
            'operating_income' => round($operatingIncome, 2),
            'other_income' => 0,
            'other_expenses' => 0,
            'net_income_before_tax' => round($operatingIncome, 2),
            'tax_expense' => round($taxExpense, 2),
            'net_income' => round($operatingIncome - $taxExpense, 2),
        ];
    }

    public function balanceSheet(int $businessId, int $periodId): array
    {
        $assetType = AccountType::where('name', 'Asset')->first();
        $liabilityType = AccountType::where('name', 'Liability')->first();
        $equityType = AccountType::where('name', 'Equity')->first();

        $assets = $this->getAccountsWithBalances($businessId, $periodId, $assetType?->id);
        $liabilities = $this->getAccountsWithBalances($businessId, $periodId, $liabilityType?->id);
        $equities = $this->getAccountsWithBalances($businessId, $periodId, $equityType?->id);

        $totalAssets = collect($assets)->sum('balance');
        $totalLiabilities = collect($liabilities)->sum('balance');
        $totalEquity = collect($equities)->sum('balance');

        // Include current period net income in equity for a complete balance sheet
        $is = $this->incomeStatement($businessId, $periodId);
        $netIncome = $is['net_income'] ?? 0;
        $adjustedEquity = $totalEquity + $netIncome;

        $totalLiabilitiesAndEquity = $totalLiabilities + $adjustedEquity;
        $isBalanced = abs($totalAssets - $totalLiabilitiesAndEquity) < 0.01;

        return [
            'sections' => [
                'assets' => $assets,
                'liabilities' => $liabilities,
                'equity' => $equities,
            ],
            'total_assets' => round($totalAssets, 2),
            'total_liabilities' => round($totalLiabilities, 2),
            'total_equity' => round($adjustedEquity, 2),
            'is_balanced' => $isBalanced,
        ];
    }

    protected function getAccountsWithBalances(int $businessId, int $periodId, ?int $typeId): array
    {
        if (!$typeId) {
            return [];
        }

        $accounts = ChartOfAccount::where('business_id', $businessId)
            ->where('type_id', $typeId)
            ->where('is_active', true)
            ->get();

        $result = [];
        foreach ($accounts as $account) {
            $balance = $this->ledgerService->calculateAccountBalance($account->id, $businessId, $periodId);
            $result[] = [
                'account_code' => $account->code,
                'account_name' => $account->name,
                'balance' => $balance,
            ];
        }

        return $result;
    }
}
