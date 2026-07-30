<?php

namespace App\Repositories\Contracts;

use App\Models\IncomeSource;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;

interface IncomeSourceRepositoryInterface
{
    public function all(int $businessId, array $filters = []): LengthAwarePaginator;

    public function find(int $id): ?IncomeSource;

    public function create(array $data): IncomeSource;

    public function update(IncomeSource $incomeSource, array $data): IncomeSource;

    public function delete(IncomeSource $incomeSource): bool;

    public function getByDateRange(int $businessId, string $start, string $end): Collection;

    public function getSummary(int $businessId, array $filters = []): array;
}
