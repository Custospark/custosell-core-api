<?php

namespace App\Services;

use App\Models\AccountType;
use App\Models\ChartOfAccount;
use App\Models\AccountingPeriod;
use App\Support\ReportPeriodContext;

class FinancialStatementService
{
  /** Parent/header COA codes - excluded from statement line totals to avoid double-counting. */
  private const PARENT_GROUP_CODES = ['1000', '1100', '1200', '1300', '2000', '2100', '2200', '3000', '4000', '5000', '6000', '6100', '6200'];

  private const COGS_CODES = ['5100', '5200', '5300'];

  private const INTEREST_EXPENSE_CODES = ['6400'];

  private const TAX_EXPENSE_CODES = ['6500'];

  public function __construct(
    protected LedgerService $ledgerService,
    protected StatementAccountResolver $accounts,
    protected CashFlowStatementBuilder $cashFlowBuilder,
    protected StatementOfEquityBuilder $equityBuilder,
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
      $signed = $this->accounts->signedRevenueContribution($account, $balance);
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

    $assets = $this->accounts->leafStatementAccounts($businessId, $periodId, $assetType?->id, 'asset');
    $liabilities = $this->accounts->leafStatementAccounts($businessId, $periodId, $liabilityType?->id, 'liability');
    $equities = $this->accounts->leafStatementAccounts($businessId, $periodId, $equityType?->id, 'equity');

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
    $period = AccountingPeriod::findOrFail($periodId);
    $prevPeriod = AccountingPeriod::where('business_id', $businessId)
      ->where('end_date', '<', $period->start_date)
      ->orderBy('end_date', 'desc')
      ->first();

    return $this->cashFlowBuilder->build(
      $businessId,
      $periodId,
      $prevPeriod?->id,
      [$periodId],
      $periodId,
    );
  }

  public function statementOfEquity(int $businessId, int $periodId): array
  {
    $is = $this->incomeStatement($businessId, $periodId);
    $netIncome = (float) ($is['net_income'] ?? 0);

    $period = AccountingPeriod::findOrFail($periodId);
    $prevPeriod = AccountingPeriod::where('business_id', $businessId)
      ->where('end_date', '<', $period->start_date)
      ->orderBy('end_date', 'desc')
      ->first();

    return $this->equityBuilder->build(
      $businessId,
      $periodId,
      $prevPeriod?->id,
      [$periodId],
      $netIncome,
      $periodId,
    );
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

    $result = $this->cashFlowBuilder->build(
      $businessId,
      $ctx->snapshotPeriodId,
      $ctx->priorSnapshotPeriodId,
      $ctx->periodIds,
      $ctx->snapshotPeriodId,
    );

    return $this->attachReportPeriodMeta($result, $ctx);
  }

  public function statementOfEquityForContext(int $businessId, ReportPeriodContext $ctx): array
  {
    if ($ctx->isSinglePeriod()) {
      return $this->attachReportPeriodMeta($this->statementOfEquity($businessId, $ctx->primaryPeriodId()), $ctx);
    }

    $is = $this->incomeStatementForPeriods($businessId, $ctx->periodIds);
    $netIncome = (float) ($is['net_income'] ?? 0);

    $result = $this->equityBuilder->build(
      $businessId,
      $ctx->snapshotPeriodId,
      $ctx->priorSnapshotPeriodId,
      $ctx->periodIds,
      $netIncome,
      $ctx->snapshotPeriodId,
    );

    return $this->attachReportPeriodMeta($result, $ctx);
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
