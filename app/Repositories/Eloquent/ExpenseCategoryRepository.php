<?php

namespace App\Repositories\Eloquent;

use App\Models\ExpenseCategory;
use App\Repositories\Contracts\ExpenseCategoryRepositoryInterface;
use Illuminate\Database\Eloquent\Collection;

class ExpenseCategoryRepository implements ExpenseCategoryRepositoryInterface
{
    public function all(int $businessId): Collection
    {
        return ExpenseCategory::where('business_id', $businessId)
            ->orderBy('sort_order')
            ->get();
    }

    public function find(int $id): ?ExpenseCategory
    {
        return ExpenseCategory::find($id);
    }

    public function create(array $data): ExpenseCategory
    {
        return ExpenseCategory::create($data);
    }

    public function update(ExpenseCategory $expenseCategory, array $data): ExpenseCategory
    {
        $expenseCategory->update($data);
        return $expenseCategory->fresh();
    }

    public function delete(ExpenseCategory $expenseCategory): bool
    {
        return $expenseCategory->delete();
    }
}
