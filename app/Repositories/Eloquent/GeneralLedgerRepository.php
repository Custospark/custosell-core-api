<?php

namespace App\Repositories\Eloquent;

use App\Models\ChartOfAccount;
use App\Models\GeneralLedger;
use App\Repositories\Contracts\GeneralLedgerRepositoryInterface;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

class GeneralLedgerRepository implements GeneralLedgerRepositoryInterface
{
    public function getBalance(int $businessId, int $accountId, int $periodId): ?GeneralLedger
    {
        return GeneralLedger::where('business_id', $businessId)
            ->where('account_id', $accountId)
            ->where('period_id', $periodId)
            ->first();
    }

    public function updateOrCreate(array $data): GeneralLedger
    {
        return GeneralLedger::updateOrCreate(
            [
                'business_id' => $data['business_id'],
                'account_id' => $data['account_id'],
                'period_id' => $data['period_id'],
            ],
            $data
        );
    }

    public function getBalancesForPeriod(int $businessId, int $periodId): Collection
    {
        return GeneralLedger::where('business_id', $businessId)
            ->where('period_id', $periodId)
            ->with(['chartOfAccount'])
            ->get();
    }

    public function getTrialBalance(int $businessId, int $periodId): \Illuminate\Support\Collection
    {
        return DB::table('general_ledger')
            ->join('chart_of_accounts', 'general_ledger.account_id', '=', 'chart_of_accounts.id')
            ->where('general_ledger.business_id', $businessId)
            ->where('general_ledger.period_id', $periodId)
            ->select(
                'chart_of_accounts.id as account_id',
                'chart_of_accounts.code as account_code',
                'chart_of_accounts.name as account_name',
                'chart_of_accounts.normal_balance',
                'general_ledger.opening_balance',
                'general_ledger.total_debits',
                'general_ledger.total_credits',
                'general_ledger.closing_balance'
            )
            ->orderBy('chart_of_accounts.code')
            ->get();
    }

    public function getAccountBalances(int $businessId, array $accountIds, int $periodId): Collection
    {
        return GeneralLedger::where('business_id', $businessId)
            ->whereIn('account_id', $accountIds)
            ->where('period_id', $periodId)
            ->with(['chartOfAccount'])
            ->get();
    }
}
