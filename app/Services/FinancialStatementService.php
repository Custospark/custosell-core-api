<?php

namespace App\Services;

use App\Models\AccountType;
use App\Models\ChartOfAccount;
use App\Support\ReportPeriodContext;

class FinancialStatementService
{
  /** Parent/header COA codes — excluded from statement line totals to avoid double-counting. */
  private const PARENT_GROUP_CODES = ['1000', '1100', '1200', '1300', '2000', '2100', '2200', '3000', '4000', '5000', '6000', '6100', '6200'];

  private const COGS_CODES = ['5100', '5200', '5300'];

  private const INTEREST_EXPENSE_CODES = ['6400'];

  private const TAX_EXPENSE_CODES = ['6500'];

  public function __construct(
    protected LedgerService $ledgerService,
  ) {}

  public function incomeStatement(int $businessId, int $periodId): array
  {
    return $this->incomeStatementForPeriods($businessId, [$periodId]);
  }

  /**
   * @param  int[]  $periodIds
   */
  public function incomeStatementForPeriods(int $businessId, array $periodIds): array
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
    $totalRevenue = 0.0;
    foreach ($revenueAccounts as $account) {
      $balance = $this->ledgerService->calculateAccountBalanceForPeriods($account->id, $businessId, $periodIds);
      if ($balance == 0 && in_array($account->code, self::PARENT_GROUP_CODES, true)) {
        continue;
      }
      $signed = $this->signedRevenueContribution($account, $balance);
      $revenues[] = [
        'account_code' => $account->code,
        'account_name' => $account->name,
        'balance' => round($signed, 2),
      ];
      $totalRevenue += $signed;
    }

    $expenses = [];
    $totalExpenses = 0.0;
    foreach ($expenseAccounts as $account) {
      $balance = $this->ledgerService->calculateAccountBalanceForPeriods($account->id, $businessId, $periodIds);
      if ($balance == 0 && in_array($account->code, self::PARENT_GROUP_CODES, true)) {
        continue;
      }
      $expenses[] = [
        'account_code' => $account->code,
        'account_name' => $account->name,
        'balance' => round($balance, 2),
      ];
      $totalExpenses += $balance;
    }

    $cogsAccounts = array_values(array_filter($expenses, fn ($e) => in_array($e['account_code'], self::COGS_CODES, true)));
    $cogs = array_sum(array_column($cogsAccounts, 'balance'));

    $interestAccounts = array_values(array_filter($expenses, fn ($e) => in_array($e['account_code'], self::INTEREST_EXPENSE_CODES, true)));
    $interestExpense = array_sum(array_column($interestAccounts, 'balance'));

    $taxAccounts = array_values(array_filter($expenses, fn ($e) => in_array($e['account_code'], self::TAX_EXPENSE_CODES, true)));
    $taxExpense = array_sum(array_column($taxAccounts, 'balance'));

    $operatingExpenses = array_values(array_filter($expenses, function ($e) {
      return !in_array($e['account_code'], array_merge(
        self::COGS_CODES,
        self::INTEREST_EXPENSE_CODES,
        self::TAX_EXPENSE_CODES,
        self::PARENT_GROUP_CODES,
      ), true);
    }));
    $totalOperatingExpenses = array_sum(array_column($operatingExpenses, 'balance'));

    $grossProfit = $totalRevenue - $cogs;
    $operatingIncome = $grossProfit - $totalOperatingExpenses;
    $netIncomeBeforeTax = $operatingIncome - $interestExpense;
    $netIncome = $netIncomeBeforeTax - $taxExpense;

    return [
      'sections' => [
        'revenue' => $revenues,
        'cost_of_goods_sold' => $cogsAccounts,
        'operating_expenses' => $operatingExpenses,
        'interest_expense' => $interestAccounts,
        'tax_expense' => $taxAccounts,
      ],
      'total_revenue' => round($totalRevenue, 2),
      'total_cost_of_goods_sold' => round($cogs, 2),
      'gross_profit' => round($grossProfit, 2),
      'total_operating_expenses' => round($totalOperatingExpenses, 2),
      'total_expenses' => round($totalExpenses, 2),
      'operating_income' => round($operatingIncome, 2),
      'interest_expense' => round($interestExpense, 2),
      'other_income' => 0,
      'other_expenses' => round($interestExpense, 2),
      'net_income_before_tax' => round($netIncomeBeforeTax, 2),
      'tax_expense' => round($taxExpense, 2),
      'net_income' => round($netIncome, 2),
    ];
  }

  public function balanceSheet(int $businessId, int $periodId): array
  {
    $assetType = AccountType::where('name', 'Asset')->first();
    $liabilityType = AccountType::where('name', 'Liability')->first();
    $equityType = AccountType::where('name', 'Equity')->first();

    $assets = $this->getLeafStatementAccounts($businessId, $periodId, $assetType?->id, 'asset');
    $liabilities = $this->getLeafStatementAccounts($businessId, $periodId, $liabilityType?->id, 'liability');
    $equities = $this->getLeafStatementAccounts($businessId, $periodId, $equityType?->id, 'equity');

    $totalAssets = collect($assets)->sum('balance');
    $totalLiabilities = collect($liabilities)->sum('balance');
    $ledgerEquity = collect($equities)->sum('balance');

    $is = $this->incomeStatement($businessId, $periodId);
    $netIncome = $is['net_income'] ?? 0;
    $totalEquity = $ledgerEquity + $netIncome;

    $totalLiabilitiesAndEquity = $totalLiabilities + $totalEquity;
    $isBalanced = abs($totalAssets - $totalLiabilitiesAndEquity) < 0.01;

    return [
      'sections' => [
        'assets' => $assets,
        'liabilities' => $liabilities,
        'equity' => $equities,
      ],
      'total_assets' => round($totalAssets, 2),
      'total_liabilities' => round($totalLiabilities, 2),
      'ledger_equity' => round($ledgerEquity, 2),
      'current_period_net_income' => round($netIncome, 2),
      'total_equity' => round($totalEquity, 2),
      'total_liabilities_and_equity' => round($totalLiabilitiesAndEquity, 2),
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

    $currentAssets = $this->getLeafStatementAccounts($businessId, $periodId, $assetTypeId, 'asset');
    $currentLiabilities = $this->getLeafStatementAccounts($businessId, $periodId, $liabilityTypeId, 'liability');

    $prevAssets = $prevId ? $this->getLeafStatementAccounts($businessId, $prevId, $assetTypeId, 'asset') : [];
    $prevLiabilities = $prevId ? $this->getLeafStatementAccounts($businessId, $prevId, $liabilityTypeId, 'liability') : [];

    $getBal = function (array $list, string $code): float {
      foreach ($list as $item) {
        if ($item['account_code'] === $code) {
          return (float) $item['balance'];
        }
      }
      return 0.0;
    };

    $depreciationAccount = ChartOfAccount::where('business_id', $businessId)->where('code', '6300')->first();
    $depreciation = $depreciationAccount
      ? $this->ledgerService->calculateAccountBalance($depreciationAccount->id, $businessId, $periodId)
      : 0;

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

    $fixedAssetAccount = ChartOfAccount::where('business_id', $businessId)->where('code', '1203')->first();
    $fixedAssetPurchases = $fixedAssetAccount
      ? max(0, $this->ledgerService->calculateAccountBalance($fixedAssetAccount->id, $businessId, $periodId)
        - ($prevId ? $this->ledgerService->calculateAccountBalance($fixedAssetAccount->id, $businessId, $prevId) : 0))
      : 0;

    $investingItems = [
      ['label' => 'Purchase of Fixed Assets', 'amount' => -$fixedAssetPurchases],
    ];
    $investingTotal = array_sum(array_column($investingItems, 'amount'));

    $loanChange = $getBal($currentLiabilities, '2201') - $getBal($prevLiabilities, '2201');

    $dividendAccount = ChartOfAccount::where('business_id', $businessId)->where('code', '3700')->first();
    $dividends = $dividendAccount
      ? abs($this->ledgerService->calculateAccountBalance($dividendAccount->id, $businessId, $periodId))
      : 0;

    $drawingsAccount = ChartOfAccount::where('business_id', $businessId)->where('code', '3300')->first();
    $drawings = $drawingsAccount
      ? $this->ledgerService->calculateAccountBalance($drawingsAccount->id, $businessId, $periodId)
      : 0;

    $financingItems = [
      ['label' => 'Change in Bank Loans', 'amount' => $loanChange],
      ['label' => 'Dividends Paid', 'amount' => -$dividends],
      ['label' => 'Owner Drawings', 'amount' => -abs($drawings)],
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
    $ledgerEquity = 0.0;

    foreach ($equityAccounts as $account) {
      if (!$this->isLeafAccount($account)) {
        continue;
      }
      $balance = $this->ledgerService->calculateAccountBalance($account->id, $businessId, $periodId);
      $signed = $this->signedBalanceForSection($account, $balance, 'equity');
      if ($signed != 0 || in_array($account->code, ['3100', '3200', '3400', '3500', '3600', '3700'], true)) {
        $equitySections[] = [
          'account_code' => $account->code,
          'account_name' => $account->name,
          'balance' => round($signed, 2),
        ];
        $ledgerEquity += $signed;
      }
    }

    $retainedEarnings = ChartOfAccount::where('business_id', $businessId)->where('code', '3200')->first();
    $retainedOpening = 0.0;
    if ($retainedEarnings) {
      $prevPeriod = \App\Models\AccountingPeriod::where('business_id', $businessId)
        ->where('end_date', '<', $period->start_date)
        ->orderBy('end_date', 'desc')
        ->first();
      if ($prevPeriod) {
        $raw = $this->ledgerService->calculateAccountBalance($retainedEarnings->id, $businessId, $prevPeriod->id);
        $retainedOpening = $this->signedBalanceForSection($retainedEarnings, $raw, 'equity');
      }
    }

    $dividendAccount = ChartOfAccount::where('business_id', $businessId)->where('code', '3700')->first();
    $dividends = $dividendAccount
      ? abs($this->ledgerService->calculateAccountBalance($dividendAccount->id, $businessId, $periodId))
      : 0;

    $closingRetained = $retainedOpening + $netIncome - $dividends;

    return [
      'opening_retained_earnings' => round($retainedOpening, 2),
      'net_income' => round($netIncome, 2),
      'dividends' => round($dividends, 2),
      'closing_retained_earnings' => round($closingRetained, 2),
      'equity_components' => $equitySections,
      'ledger_equity' => round($ledgerEquity, 2),
      'total_equity' => round($ledgerEquity + $netIncome, 2),
      'period_id' => $periodId,
    ];
  }

  /**
   * @return array<int, array{account_code: string, account_name: string, balance: float}>
   */
  protected function getLeafStatementAccounts(int $businessId, int $periodId, ?int $typeId, string $section): array
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
      if (!$this->isLeafAccount($account)) {
        continue;
      }
      $raw = $this->ledgerService->calculateAccountBalance($account->id, $businessId, $periodId);
      $signed = $this->signedBalanceForSection($account, $raw, $section);
      if (abs($signed) < 0.005) {
        continue;
      }
      $result[] = [
        'account_code' => $account->code,
        'account_name' => $account->name,
        'balance' => round($signed, 2),
      ];
    }

    return $result;
  }

  protected function isLeafAccount(ChartOfAccount $account): bool
  {
    return !ChartOfAccount::where('parent_id', $account->id)->exists();
  }

  protected function signedBalanceForSection(ChartOfAccount $account, float $balance, string $section): float
  {
    return match ($section) {
      'asset' => $account->normal_balance === 'debit' ? $balance : -$balance,
      'liability', 'equity' => $account->normal_balance === 'credit' ? $balance : -$balance,
      default => $balance,
    };
  }

  protected function signedRevenueContribution(ChartOfAccount $account, float $balance): float
  {
    return $account->normal_balance === 'credit' ? $balance : -$balance;
  }

  /** @deprecated Use getLeafStatementAccounts() */
  protected function getAccountsWithBalances(int $businessId, int $periodId, ?int $typeId): array
  {
    if (!$typeId) {
      return [];
    }

    $section = match (AccountType::find($typeId)?->name) {
      'Asset' => 'asset',
      'Liability' => 'liability',
      'Equity' => 'equity',
      default => 'asset',
    };

    return $this->getLeafStatementAccounts($businessId, $periodId, $typeId, $section);
  }

  public function incomeStatementForContext(int $businessId, ReportPeriodContext $ctx): array
  {
    $result = $this->incomeStatementForPeriods($businessId, $ctx->periodIds);

    return $this->attachReportPeriodMeta($result, $ctx);
  }

  public function balanceSheetForContext(int $businessId, ReportPeriodContext $ctx): array
  {
    $sheet = $this->balanceSheet($businessId, $ctx->snapshotPeriodId);

    if ($ctx->isRange) {
      $rangeNetIncome = $this->incomeStatementForPeriods($businessId, $ctx->periodIds)['net_income'] ?? 0;
      $monthlyNet = $sheet['current_period_net_income'] ?? 0;
      $delta = $rangeNetIncome - $monthlyNet;
      $sheet['current_period_net_income'] = round($rangeNetIncome, 2);
      $sheet['total_equity'] = round($sheet['total_equity'] + $delta, 2);
      $sheet['total_liabilities_and_equity'] = round($sheet['total_liabilities'] + $sheet['total_equity'], 2);
      $sheet['is_balanced'] = abs($sheet['total_assets'] - $sheet['total_liabilities_and_equity']) < 0.01;
    }

    return $this->attachReportPeriodMeta($sheet, $ctx);
  }

  public function cashFlowStatementForContext(int $businessId, ReportPeriodContext $ctx): array
  {
    if ($ctx->isSinglePeriod()) {
      return $this->attachReportPeriodMeta($this->cashFlowStatement($businessId, $ctx->primaryPeriodId()), $ctx);
    }

    $is = $this->incomeStatementForPeriods($businessId, $ctx->periodIds);
    $netIncome = $is['net_income'] ?? 0;

    $prevId = $ctx->priorSnapshotPeriodId;
    $snapshotId = $ctx->snapshotPeriodId;

    $assetTypeId = AccountType::where('name', 'Asset')->first()?->id;
    $liabilityTypeId = AccountType::where('name', 'Liability')->first()?->id;

    $currentAssets = $this->getLeafStatementAccounts($businessId, $snapshotId, $assetTypeId, 'asset');
    $currentLiabilities = $this->getLeafStatementAccounts($businessId, $snapshotId, $liabilityTypeId, 'liability');
    $prevAssets = $prevId ? $this->getLeafStatementAccounts($businessId, $prevId, $assetTypeId, 'asset') : [];
    $prevLiabilities = $prevId ? $this->getLeafStatementAccounts($businessId, $prevId, $liabilityTypeId, 'liability') : [];

    $getBal = function (array $list, string $code): float {
      foreach ($list as $item) {
        if ($item['account_code'] === $code) {
          return (float) $item['balance'];
        }
      }

      return 0.0;
    };

    $depreciationAccount = ChartOfAccount::where('business_id', $businessId)->where('code', '6300')->first();
    $depreciation = $depreciationAccount
      ? $this->ledgerService->calculateAccountBalanceForPeriods($depreciationAccount->id, $businessId, $ctx->periodIds)
      : 0;

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

    $fixedAssetAccount = ChartOfAccount::where('business_id', $businessId)->where('code', '1203')->first();
    $fixedAssetPurchases = $fixedAssetAccount
      ? max(0, $this->ledgerService->calculateAccountBalanceForPeriods($fixedAssetAccount->id, $businessId, $ctx->periodIds)
        - ($prevId ? $this->ledgerService->calculateAccountBalance($fixedAssetAccount->id, $businessId, $prevId) : 0))
      : 0;

    $investingItems = [['label' => 'Purchase of Fixed Assets', 'amount' => -$fixedAssetPurchases]];
    $investingTotal = array_sum(array_column($investingItems, 'amount'));

    $loanChange = $getBal($currentLiabilities, '2201') - $getBal($prevLiabilities, '2201');
    $dividendAccount = ChartOfAccount::where('business_id', $businessId)->where('code', '3700')->first();
    $dividends = $dividendAccount
      ? abs($this->ledgerService->calculateAccountBalanceForPeriods($dividendAccount->id, $businessId, $ctx->periodIds))
      : 0;
    $drawingsAccount = ChartOfAccount::where('business_id', $businessId)->where('code', '3300')->first();
    $drawings = $drawingsAccount
      ? $this->ledgerService->calculateAccountBalanceForPeriods($drawingsAccount->id, $businessId, $ctx->periodIds)
      : 0;

    $financingItems = [
      ['label' => 'Change in Bank Loans', 'amount' => $loanChange],
      ['label' => 'Dividends Paid', 'amount' => -$dividends],
      ['label' => 'Owner Drawings', 'amount' => -abs($drawings)],
    ];
    $financingTotal = array_sum(array_column($financingItems, 'amount'));
    $netChange = $operatingTotal + $investingTotal + $financingTotal;

    return $this->attachReportPeriodMeta([
      'operating' => ['items' => $operatingItems, 'total' => round($operatingTotal, 2)],
      'investing' => ['items' => $investingItems, 'total' => round($investingTotal, 2)],
      'financing' => ['items' => $financingItems, 'total' => round($financingTotal, 2)],
      'net_change' => round($netChange, 2),
      'period_id' => $ctx->snapshotPeriodId,
    ], $ctx);
  }

  public function statementOfEquityForContext(int $businessId, ReportPeriodContext $ctx): array
  {
    if ($ctx->isSinglePeriod()) {
      return $this->attachReportPeriodMeta($this->statementOfEquity($businessId, $ctx->primaryPeriodId()), $ctx);
    }

    $is = $this->incomeStatementForPeriods($businessId, $ctx->periodIds);
    $netIncome = $is['net_income'] ?? 0;

    $equityType = AccountType::where('name', 'Equity')->first();
    $equityAccounts = ChartOfAccount::where('business_id', $businessId)
      ->where('type_id', $equityType?->id)
      ->where('is_active', true)
      ->get();

    $equitySections = [];
    $ledgerEquity = 0.0;
    foreach ($equityAccounts as $account) {
      if (!$this->isLeafAccount($account)) {
        continue;
      }
      $balance = $this->ledgerService->calculateAccountBalance($account->id, $businessId, $ctx->snapshotPeriodId);
      $signed = $this->signedBalanceForSection($account, $balance, 'equity');
      if ($signed != 0 || in_array($account->code, ['3100', '3200', '3400', '3500', '3600', '3700'], true)) {
        $equitySections[] = [
          'account_code' => $account->code,
          'account_name' => $account->name,
          'balance' => round($signed, 2),
        ];
        $ledgerEquity += $signed;
      }
    }

    $retainedEarnings = ChartOfAccount::where('business_id', $businessId)->where('code', '3200')->first();
    $retainedOpening = 0.0;
    if ($retainedEarnings && $ctx->priorSnapshotPeriodId) {
      $raw = $this->ledgerService->calculateAccountBalance($retainedEarnings->id, $businessId, $ctx->priorSnapshotPeriodId);
      $retainedOpening = $this->signedBalanceForSection($retainedEarnings, $raw, 'equity');
    }

    $dividendAccount = ChartOfAccount::where('business_id', $businessId)->where('code', '3700')->first();
    $dividends = $dividendAccount
      ? abs($this->ledgerService->calculateAccountBalanceForPeriods($dividendAccount->id, $businessId, $ctx->periodIds))
      : 0;

    $closingRetained = $retainedOpening + $netIncome - $dividends;

    return $this->attachReportPeriodMeta([
      'opening_retained_earnings' => round($retainedOpening, 2),
      'net_income' => round($netIncome, 2),
      'dividends' => round($dividends, 2),
      'closing_retained_earnings' => round($closingRetained, 2),
      'equity_components' => $equitySections,
      'ledger_equity' => round($ledgerEquity, 2),
      'total_equity' => round($ledgerEquity + $netIncome, 2),
      'period_id' => $ctx->snapshotPeriodId,
    ], $ctx);
  }

  protected function attachReportPeriodMeta(array $payload, ReportPeriodContext $ctx): array
  {
    $payload['period'] = [
      'id' => $ctx->snapshotPeriodId,
      'name' => $ctx->label,
      'start_date' => $ctx->dateFrom,
      'end_date' => $ctx->dateTo,
      'is_closed' => false,
      'period_ids' => $ctx->periodIds,
      'is_range' => $ctx->isRange,
    ];

    return $payload;
  }
}
