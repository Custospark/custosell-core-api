<?php

namespace App\Repositories\Contracts;

use App\Models\GeneralLedger;

interface GeneralLedgerRepositoryInterface
{
    public function getBalance(int $businessId, int $accountId, int $periodId): ?GeneralLedger;

    public function updateOrCreate(array $data): GeneralLedger;

    public function getBalancesForPeriod(int $businessId, int $periodId): \Illuminate\Database\Eloquent\Collection;

    public function getTrialBalance(int $businessId, int $periodId): \Illuminate\Support\Collection;

    public function getAccountBalances(int $businessId, array $accountIds, int $periodId): \Illuminate\Database\Eloquent\Collection;
}
