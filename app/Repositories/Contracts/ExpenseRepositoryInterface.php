<?php

namespace App\Repositories\Contracts;

use App\Models\Expense;
use Illuminate\Database\Eloquent\Collection;

interface ExpenseRepositoryInterface
{
    public function all(int $businessId): Collection;

    public function find(int $id): ?Expense;

    public function create(array $data): Expense;

    public function update(Expense $expense, array $data): Expense;

    public function delete(Expense $expense): bool;

    public function getByDateRange(int $businessId, string $start, string $end): Collection;

    public function getByCategory(int $businessId, int $categoryId): Collection;
}
