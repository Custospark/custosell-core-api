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

        $netIncome = $totalRevenue - $totalExpenses;

        return [
            'revenues' => $revenues,
            'total_revenue' => round($totalRevenue, 2),
            'expenses' => $expenses,
            'total_expenses' => round($totalExpenses, 2),
            'net_income' => round($netIncome, 2),
            'period_id' => $periodId,
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

        return [
            'assets' => $assets,
            'total_assets' => round($totalAssets, 2),
            'liabilities' => $liabilities,
            'total_liabilities' => round($totalLiabilities, 2),
            'equities' => $equities,
            'total_equity' => round($totalEquity, 2),
            'total_liabilities_and_equity' => round($totalLiabilities + $totalEquity, 2),
            'period_id' => $periodId,
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
