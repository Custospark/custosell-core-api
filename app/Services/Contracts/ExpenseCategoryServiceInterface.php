<?php

namespace App\Services\Contracts;

use App\Models\ExpenseCategory;
use Illuminate\Database\Eloquent\Collection;

interface ExpenseCategoryServiceInterface
{
    public function getAll(int $businessId): Collection;

    public function getById(int $id): ?ExpenseCategory;

    public function getByIdForBusiness(int $businessId, int $id): ?ExpenseCategory;

    public function create(int $businessId, array $data): ExpenseCategory;

    public function update(int $businessId, int $id, array $data): ExpenseCategory;

    public function delete(int $businessId, int $id): bool;
}
