<?php

namespace App\Services\Contracts;

use App\Models\IncomeSource;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;

interface IncomeSourceServiceInterface
{
    public function getAll(int $businessId, array $filters = []): LengthAwarePaginator;

    public function getById(int $id): ?IncomeSource;

    public function create(int $businessId, int $userId, array $data): IncomeSource;

    public function update(int $id, array $data): IncomeSource;

    public function delete(int $id): bool;

    public function getByDateRange(int $businessId, string $start, string $end): Collection;

    public function getSummary(int $businessId, array $filters = []): array;
}
