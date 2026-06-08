<?php

namespace App\Repositories\Contracts;

use App\Models\Expense;
use Illuminate\Database\Eloquent\Collection;

interface ExpenseRepositoryInterface
{
    public function all(int $businessId, array $filters = []): \Illuminate\Contracts\Pagination\LengthAwarePaginator;

    public function find(int $id): ?Expense;

    public function create(array $data): Expense;

    public function update(Expense $expense, array $data): Expense;

    public function delete(Expense $expense): bool;

    public function getByDateRange(int $businessId, string $start, string $end): \Illuminate\Database\Eloquent\Collection;

    public function getByCategory(int $businessId, int $categoryId): \Illuminate\Database\Eloquent\Collection;

    public function getByShift(int $businessId, int $shiftId): \Illuminate\Database\Eloquent\Collection;

    public function getSummary(int $businessId, array $filters = []): array;
}
