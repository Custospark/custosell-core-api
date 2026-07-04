<?php

namespace App\Repositories\Contracts;

use App\Models\AccountingPeriod;
use Illuminate\Database\Eloquent\Collection;

interface AccountingPeriodRepositoryInterface
{
    public function all(int $businessId): Collection;

    public function find(int $id): ?AccountingPeriod;

    public function getCurrentPeriod(int $businessId): ?AccountingPeriod;

    public function getPeriodByDate(int $businessId, string $date): ?AccountingPeriod;

    public function create(array $data): AccountingPeriod;

    public function update(AccountingPeriod $period, array $data): AccountingPeriod;

    public function close(int $id, int $userId): AccountingPeriod;

    public function reopen(int $id, int $userId): AccountingPeriod;

    public function findOrCreatePeriod(int $businessId, string $date): AccountingPeriod;
}
