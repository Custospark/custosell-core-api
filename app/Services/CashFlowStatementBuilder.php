<?php

namespace App\Services;

use App\Models\AccountType;
use App\Models\ChartOfAccount;
use App\Models\AccountingPeriod;
use App\Support\ReportPeriodContext;

class CashFlowStatementBuilder
{
  public function __construct(
    protected LedgerService $ledgerService,
    protected StatementAccountResolver $accounts,
  ) {}

  /**
   * Build the cash flow statement for a period or a range of periods.
   *
   * @param  int[]|null  $periodIds  Periods included in the range (for period-based income/balances).
   */
  public function build(int $businessId, int $snapshotPeriodId, ?int $priorPeriodId, array $periodIds, int $metaPeriodId): array
  {
    $is = app(FinancialStatementService::class)->incomeStatementForPeriods($businessId, $periodIds);
    $netIncome = $is['net_income'] ?? 0;

    $assetTypeId = AccountType::where('name', 'Asset')->first()?->id;
    $liabilityTypeId = AccountType::where('name', 'Liability')->first()?->id;

    $currentAssets = $this->accounts->leafStatementAccounts($businessId, $snapshotPeriodId, $assetTypeId, 'asset');
    $currentLiabilities = $this->accounts->leafStatementAccounts($businessId, $snapshotPeriodId, $liabilityTypeId, 'liability');

    $prevAssets = $priorPeriodId ? $this->accounts->leafStatementAccounts($businessId, $priorPeriodId, $assetTypeId, 'asset') : [];
    $prevLiabilities = $priorPeriodId ? $this->accounts->leafStatementAccounts($businessId, $priorPeriodId, $liabilityTypeId, 'liability') : [];

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
      ? $this->ledgerService->calculateAccountBalanceForPeriods($depreciationAccount->id, $businessId, $periodIds)
      : 0;

    $arChange = $getBal($currentAssets, '1103') - $getBal($prevAssets, '1103');
    $invChange = $getBal($currentAssets, '1104') - $getBal($prevAssets, '1104');
    $prepaidChange = $getBal($currentAssets, '1105') - $getBal($prevAssets, '1105');
    $apChange = $getBal($currentLiabilities, '2101') - $getBal($prevLiabilities, '2101');
    $vatChange = $getBal($currentLiabilities, '2102') - $getBal($prevLiabilities, '2102');
    $accruedChange = $getBal($currentLiabilities, '2103') - $getBal($prevLiabilities, '2103');
    $salariesPayChange = $getBal($currentLiabilities, '2110') - $getBal($prevLiabilities, '2110');
    $payePayChange = $getBal($currentLiabilities, '2111') - $getBal($prevLiabilities, '2111');
    $nssfPayChange = $getBal($currentLiabilities, '2112') - $getBal($prevLiabilities, '2112');

    $operatingItems = [
      ['label' => 'Net Income', 'amount' => $netIncome],
      ['label' => 'Depreciation & Amortization', 'amount' => abs($depreciation)],
      ['label' => 'Change in Accounts Receivable', 'amount' => -$arChange],
      ['label' => 'Change in Inventory', 'amount' => -$invChange],
      ['label' => 'Change in Prepaid Expenses', 'amount' => -$prepaidChange],
      ['label' => 'Change in Accounts Payable', 'amount' => $apChange],
      ['label' => 'Change in VAT Payable', 'amount' => $vatChange],
      ['label' => 'Change in Accrued Expenses', 'amount' => $accruedChange],
      ['label' => 'Change in Salaries Payable', 'amount' => $salariesPayChange],
      ['label' => 'Change in PAYE Payable', 'amount' => $payePayChange],
      ['label' => 'Change in NSSF Payable', 'amount' => $nssfPayChange],
    ];
    $operatingTotal = array_sum(array_column($operatingItems, 'amount'));

    $fixedAssetAccount = ChartOfAccount::where('business_id', $businessId)->where('code', '1203')->first();
    $fixedAssetPurchases = $fixedAssetAccount
      ? max(0, $this->ledgerService->calculateAccountBalanceForPeriods($fixedAssetAccount->id, $businessId, $periodIds)
        - ($priorPeriodId ? $this->ledgerService->calculateAccountBalance($fixedAssetAccount->id, $businessId, $priorPeriodId) : 0))
      : 0;

    $investingItems = [['label' => 'Purchase of Fixed Assets', 'amount' => -$fixedAssetPurchases]];
    $investingTotal = array_sum(array_column($investingItems, 'amount'));

    $loanChange = $getBal($currentLiabilities, '2201') - $getBal($prevLiabilities, '2201');
    $dividendAccount = ChartOfAccount::where('business_id', $businessId)->where('code', '3700')->first();
    $dividends = $dividendAccount
      ? abs($this->ledgerService->calculateAccountBalanceForPeriods($dividendAccount->id, $businessId, $periodIds))
      : 0;
    $drawingsAccount = ChartOfAccount::where('business_id', $businessId)->where('code', '3300')->first();
    $drawings = $drawingsAccount
      ? $this->ledgerService->calculateAccountBalanceForPeriods($drawingsAccount->id, $businessId, $periodIds)
      : 0;

    $financingItems = [
      ['label' => 'Change in Bank Loans', 'amount' => $loanChange],
      ['label' => 'Dividends Paid', 'amount' => -$dividends],
      ['label' => 'Owner Drawings', 'amount' => -abs($drawings)],
    ];
    $financingTotal = array_sum(array_column($financingItems, 'amount'));
    $netChange = $operatingTotal + $investingTotal + $financingTotal;

    return [
      'operating' => ['items' => $operatingItems, 'total' => round($operatingTotal, 2)],
      'investing' => ['items' => $investingItems, 'total' => round($investingTotal, 2)],
      'financing' => ['items' => $financingItems, 'total' => round($financingTotal, 2)],
      'net_change' => round($netChange, 2),
      'period_id' => $metaPeriodId,
    ];
  }
}
