<?php

namespace App\Services;

use App\Models\ChartOfAccount;
use App\Models\AccountType;

class StatementAccountResolver
{
  public function __construct(
    protected LedgerService $ledgerService,
  ) {}

  /**
   * @return array<int, array{account_code: string, account_name: string, balance: float}>
   */
  public function leafStatementAccounts(int $businessId, int $periodId, ?int $typeId, string $section): array
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

  public function isLeafAccount(ChartOfAccount $account): bool
  {
    return !ChartOfAccount::where('parent_id', $account->id)->exists();
  }

  public function signedBalanceForSection(ChartOfAccount $account, float $balance, string $section): float
  {
    return match ($section) {
      'asset' => $account->normal_balance === 'debit' ? $balance : -$balance,
      'liability', 'equity' => $account->normal_balance === 'credit' ? $balance : -$balance,
      default => $balance,
    };
  }

  public function signedRevenueContribution(ChartOfAccount $account, float $balance): float
  {
    return $account->normal_balance === 'credit' ? $balance : -$balance;
  }

  /** @deprecated Use leafStatementAccounts() */
  public function accountsWithBalances(int $businessId, int $periodId, ?int $typeId): array
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

    return $this->leafStatementAccounts($businessId, $periodId, $typeId, $section);
  }
}
