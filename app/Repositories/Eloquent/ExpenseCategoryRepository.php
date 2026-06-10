<?php

namespace App\Repositories\Eloquent;

use App\Models\ExpenseCategory;
use App\Repositories\Contracts\ExpenseCategoryRepositoryInterface;
use Illuminate\Database\Eloquent\Collection;

class ExpenseCategoryRepository implements ExpenseCategoryRepositoryInterface
{
    public function all(int $businessId): Collection
    {
        $system = ExpenseCategory::query()
            ->whereNull('business_id')
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();

        $custom = ExpenseCategory::query()
            ->where('business_id', $businessId)
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();

        return $system->concat($custom);
    }

    public function find(int $id): ?ExpenseCategory
    {
        return ExpenseCategory::find($id);
    }

    public function findAvailableForBusiness(int $businessId, int $categoryId): ?ExpenseCategory
    {
        return ExpenseCategory::query()
            ->whereKey($categoryId)
            ->where(function ($query) use ($businessId) {
                $query->whereNull('business_id')
                    ->orWhere('business_id', $businessId);
            })
            ->first();
    }

    public function isSystemTemplate(ExpenseCategory $category): bool
    {
        return $category->business_id === null;
    }

    public function nameExistsForBusiness(int $businessId, string $name, ?int $ignoreId = null): bool
    {
        return ExpenseCategory::query()
            ->where('name', $name)
            ->where(function ($query) use ($businessId) {
                $query->whereNull('business_id')
                    ->orWhere('business_id', $businessId);
            })
            ->when($ignoreId, fn ($query) => $query->whereKeyNot($ignoreId))
            ->exists();
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
