<?php

namespace App\Repositories\Eloquent;

use App\Models\Expense;
use App\Repositories\Contracts\ExpenseRepositoryInterface;
use Illuminate\Database\Eloquent\Collection;

class ExpenseRepository implements ExpenseRepositoryInterface
{
    public function all(int $businessId): Collection
    {
        return Expense::where('business_id', $businessId)
            ->with(['expenseCategory', 'recordedBy'])
            ->orderBy('expense_date', 'desc')
            ->get();
    }

    public function find(int $id): ?Expense
    {
        return Expense::with(['expenseCategory', 'recordedBy'])->find($id);
    }

    public function create(array $data): Expense
    {
        return Expense::create($data);
    }

    public function update(Expense $expense, array $data): Expense
    {
        $expense->update($data);
        return $expense->fresh();
    }

    public function delete(Expense $expense): bool
    {
        return $expense->delete();
    }

    public function getByDateRange(int $businessId, string $start, string $end): Collection
    {
        return Expense::where('business_id', $businessId)
            ->whereBetween('expense_date', [$start, $end])
            ->with(['expenseCategory', 'recordedBy'])
            ->orderBy('expense_date', 'desc')
            ->get();
    }

    public function getByCategory(int $businessId, int $categoryId): Collection
    {
        return Expense::where('business_id', $businessId)
            ->where('expense_category_id', $categoryId)
            ->with(['expenseCategory', 'recordedBy'])
            ->orderBy('expense_date', 'desc')
            ->get();
    }
}
