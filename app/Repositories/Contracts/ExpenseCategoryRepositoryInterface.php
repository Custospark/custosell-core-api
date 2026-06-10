<?php

namespace App\Repositories\Contracts;

use App\Models\ExpenseCategory;
use Illuminate\Database\Eloquent\Collection;

interface ExpenseCategoryRepositoryInterface
{
    public function all(int $businessId): Collection;

    public function find(int $id): ?ExpenseCategory;

    public function findAvailableForBusiness(int $businessId, int $categoryId): ?ExpenseCategory;

    public function isSystemTemplate(ExpenseCategory $category): bool;

    public function nameExistsForBusiness(int $businessId, string $name, ?int $ignoreId = null): bool;

    public function create(array $data): ExpenseCategory;

    public function update(ExpenseCategory $expenseCategory, array $data): ExpenseCategory;

    public function delete(ExpenseCategory $expenseCategory): bool;
}
