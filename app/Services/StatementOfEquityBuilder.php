<?php

namespace App\Services;

use App\Models\AccountType;
use App\Models\ChartOfAccount;
use App\Models\AccountingPeriod;
use App\Support\ReportPeriodContext;

class StatementOfEquityBuilder
{
  public function __construct(
    protected LedgerService $ledgerService,
    protected StatementAccountResolver $accounts,
  ) {}

  /**
   * Build the statement of equity.
   *
   * @param  int[]  $periodIds  Periods for range income/dividends.
   */
  public function build(int $businessId, int $snapshotPeriodId, ?int $priorSnapshotPeriodId, array $periodIds, float $netIncome, int $metaPeriodId): array
  {
    $equityType = AccountType::where('name', 'Equity')->first();
    $equityAccounts = ChartOfAccount::where('business_id', $businessId)
      ->where('type_id', $equityType?->id)
      ->where('is_active', true)
      ->get();

    $equitySections = [];
    $ledgerEquity = 0.0;
    foreach ($equityAccounts as $account) {
      if (!$this->accounts->isLeafAccount($account)) {
        continue;
      }
      $balance = $this->ledgerService->calculateAccountBalance($account->id, $businessId, $snapshotPeriodId);
      $signed = $this->accounts->signedBalanceForSection($account, $balance, 'equity');
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
    if ($retainedEarnings && $priorSnapshotPeriodId) {
      $raw = $this->ledgerService->calculateAccountBalance($retainedEarnings->id, $businessId, $priorSnapshotPeriodId);
      $retainedOpening = $this->accounts->signedBalanceForSection($retainedEarnings, $raw, 'equity');
    }

    $dividendAccount = ChartOfAccount::where('business_id', $businessId)->where('code', '3700')->first();
    $dividends = $dividendAccount
      ? abs($this->ledgerService->calculateAccountBalanceForPeriods($dividendAccount->id, $businessId, $periodIds))
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
      'period_id' => $metaPeriodId,
    ];
  }
}
