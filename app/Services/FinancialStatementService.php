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
            // Skip zero-balance grouping accounts
            if ($balance == 0 && in_array($account->code, ['4000'])) continue;
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
            // Skip zero-balance grouping accounts
            if ($balance == 0 && in_array($account->code, ['5000', '6000', '6100', '6200'])) continue;
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

        // Operating expenses = all expenses minus COGS minus parent grouping accounts
        $operatingExpenses = array_values(array_filter($expenses, function ($e) {
            return !in_array($e['account_code'], ['5100', '5200', '5300', '6400', '6500', '6300', '5000', '6000']);
        }));
        $totalOperatingExpenses = array_sum(array_column($operatingExpenses, 'balance'));

        $grossProfit = $totalRevenue - $cogs;
        $operatingIncome = $grossProfit - $totalOperatingExpenses;

        return [
            'sections' => [
                'revenue' => $revenues,
                'cost_of_goods_sold' => array_values($cogsAccounts),
                'operating_expenses' => $operatingExpenses,
            ],
            'total_revenue' => round($totalRevenue, 2),
            'total_cost_of_goods_sold' => round($cogs, 2),
            'gross_profit' => round($grossProfit, 2),
            'total_operating_expenses' => round($totalOperatingExpenses, 2),
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

    public function cashFlowStatement(int $businessId, int $periodId): array
    {
        $is = $this->incomeStatement($businessId, $periodId);
        $netIncome = $is['net_income'] ?? 0;

        $period = \App\Models\AccountingPeriod::findOrFail($periodId);
        $prevPeriod = \App\Models\AccountingPeriod::where('business_id', $businessId)
            ->where('end_date', '<', $period->start_date)
            ->orderBy('end_date', 'desc')
            ->first();

        $prevId = $prevPeriod?->id;

        $assetTypeId = AccountType::where('name', 'Asset')->first()?->id;
        $liabilityTypeId = AccountType::where('name', 'Liability')->first()?->id;

        $currentAssets = $this->getAccountsWithBalances($businessId, $periodId, $assetTypeId);
        $currentLiabilities = $this->getAccountsWithBalances($businessId, $periodId, $liabilityTypeId);

        $prevAssets = $prevId ? $this->getAccountsWithBalances($businessId, $prevId, $assetTypeId) : [];
        $prevLiabilities = $prevId ? $this->getAccountsWithBalances($businessId, $prevId, $liabilityTypeId) : [];

        $getBal = function ($list, $code) {
            foreach ($list as $item) {
                if ($item['account_code'] === $code) {
                    return $item['balance'];
                }
            }
            return 0;
        };

        $depreciationAccount = ChartOfAccount::where('business_id', $businessId)->where('code', '6300')->first();
        $depreciation = $depreciationAccount ? $this->ledgerService->calculateAccountBalance($depreciationAccount->id, $businessId, $periodId) : 0;

        $arChange = $getBal($currentAssets, '1103') - $getBal($prevAssets, '1103');
        $invChange = $getBal($currentAssets, '1104') - $getBal($prevAssets, '1104');
        $prepaidChange = $getBal($currentAssets, '1105') - $getBal($prevAssets, '1105');

        $apChange = $getBal($currentLiabilities, '2101') - $getBal($prevLiabilities, '2101');
        $vatChange = $getBal($currentLiabilities, '2102') - $getBal($prevLiabilities, '2102');
        $accruedChange = $getBal($currentLiabilities, '2103') - $getBal($prevLiabilities, '2103');

        $operatingItems = [
            ['label' => 'Net Income', 'amount' => $netIncome],
            ['label' => 'Depreciation & Amortization', 'amount' => abs($depreciation)],
            ['label' => 'Change in Accounts Receivable', 'amount' => -$arChange],
            ['label' => 'Change in Inventory', 'amount' => -$invChange],
            ['label' => 'Change in Prepaid Expenses', 'amount' => -$prepaidChange],
            ['label' => 'Change in Accounts Payable', 'amount' => $apChange],
            ['label' => 'Change in VAT Payable', 'amount' => $vatChange],
            ['label' => 'Change in Accrued Expenses', 'amount' => $accruedChange],
        ];

        $operatingTotal = array_sum(array_column($operatingItems, 'amount'));

        $fixedAssetAccount = ChartOfAccount::where('business_id', $businessId)->where('code', '1200')->first();
        $fixedAssetPurchases = $fixedAssetAccount ? $this->ledgerService->calculateAccountBalance($fixedAssetAccount->id, $businessId, $periodId) : 0;

        $investingItems = [
            ['label' => 'Purchase of Fixed Assets', 'amount' => -abs($fixedAssetPurchases)],
        ];
        $investingTotal = array_sum(array_column($investingItems, 'amount'));

        $loanChange = $getBal($currentLiabilities, '2201') - $getBal($prevLiabilities, '2201');

        $dividendAccount = ChartOfAccount::where('business_id', $businessId)->where('code', '3700')->first();
        $dividends = $dividendAccount ? abs($this->ledgerService->calculateAccountBalance($dividendAccount->id, $businessId, $periodId)) : 0;

        $financingItems = [
            ['label' => 'Change in Bank Loans', 'amount' => $loanChange],
            ['label' => 'Dividends Paid', 'amount' => -$dividends],
        ];
        $financingTotal = array_sum(array_column($financingItems, 'amount'));

        $netChange = $operatingTotal + $investingTotal + $financingTotal;

        return [
            'operating' => [
                'items' => $operatingItems,
                'total' => round($operatingTotal, 2),
            ],
            'investing' => [
                'items' => $investingItems,
                'total' => round($investingTotal, 2),
            ],
            'financing' => [
                'items' => $financingItems,
                'total' => round($financingTotal, 2),
            ],
            'net_change' => round($netChange, 2),
            'period_id' => $periodId,
        ];
    }

    public function statementOfEquity(int $businessId, int $periodId): array
    {
        $is = $this->incomeStatement($businessId, $periodId);
        $netIncome = $is['net_income'] ?? 0;

        $period = \App\Models\AccountingPeriod::findOrFail($periodId);

        $equityType = AccountType::where('name', 'Equity')->first();
        $equityAccounts = ChartOfAccount::where('business_id', $businessId)
            ->where('type_id', $equityType?->id)
            ->where('is_active', true)
            ->get();

        $equitySections = [];
        $totalEquity = 0;

        foreach ($equityAccounts as $account) {
            $balance = $this->ledgerService->calculateAccountBalance($account->id, $businessId, $periodId);
            if ($balance != 0 || in_array($account->code, ['3100', '3200', '3400', '3500', '3600', '3700'])) {
                $equitySections[] = [
                    'account_code' => $account->code,
                    'account_name' => $account->name,
                    'balance' => $balance,
                ];
            }
        }

        $retainedEarnings = ChartOfAccount::where('business_id', $businessId)->where('code', '3200')->first();
        $retainedOpening = 0;
        if ($retainedEarnings) {
            $prevPeriod = \App\Models\AccountingPeriod::where('business_id', $businessId)
                ->where('end_date', '<', $period->start_date)
                ->orderBy('end_date', 'desc')
                ->first();
            if ($prevPeriod) {
                $retainedOpening = $this->ledgerService->calculateAccountBalance($retainedEarnings->id, $businessId, $prevPeriod->id);
            }
        }

        $dividendAccount = ChartOfAccount::where('business_id', $businessId)->where('code', '3700')->first();
        $dividends = $dividendAccount ? abs($this->ledgerService->calculateAccountBalance($dividendAccount->id, $businessId, $periodId)) : 0;

        return [
            'opening_retained_earnings' => round($retainedOpening, 2),
            'net_income' => round($netIncome, 2),
            'dividends' => round($dividends, 2),
            'closing_retained_earnings' => round($retainedOpening + $netIncome - $dividends, 2),
            'equity_components' => $equitySections,
            'total_equity' => round($retainedOpening + $netIncome - $dividends + array_sum(array_column($equitySections, 'balance')), 2),
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
