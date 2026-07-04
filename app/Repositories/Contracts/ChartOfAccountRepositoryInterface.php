<?php

namespace App\Repositories\Contracts;

use App\Models\ChartOfAccount;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;

interface ChartOfAccountRepositoryInterface
{
    public function all(int $businessId, array $filters = []): LengthAwarePaginator;

    public function find(int $id): ?ChartOfAccount;

    public function findByCode(int $businessId, string $code): ?ChartOfAccount;

    public function create(array $data): ChartOfAccount;

    public function update(ChartOfAccount $account, array $data): ChartOfAccount;

    public function delete(ChartOfAccount $account): bool;

    public function getTree(int $businessId): Collection;

    public function getChildren(int $accountId): Collection;

    public function getActiveAccounts(int $businessId): Collection;
}
