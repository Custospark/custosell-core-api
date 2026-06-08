<?php

namespace App\Services\Contracts;

use App\Models\Expense;
use Illuminate\Database\Eloquent\Collection;

interface ExpenseServiceInterface
{
    public function getAll(int $businessId, array $filters = []): \Illuminate\Contracts\Pagination\LengthAwarePaginator;

    public function getById(int $id): ?Expense;

    public function create(int $businessId, array $data): Expense;

    public function update(int $id, array $data): Expense;

    public function delete(int $id): bool;

    public function getByDateRange(int $businessId, string $start, string $end): \Illuminate\Database\Eloquent\Collection;

    public function getByCategory(int $businessId, int $categoryId): \Illuminate\Database\Eloquent\Collection;

    public function getByShift(int $businessId, int $shiftId): \Illuminate\Database\Eloquent\Collection;

    public function getSummary(int $businessId, array $filters = []): array;
}
