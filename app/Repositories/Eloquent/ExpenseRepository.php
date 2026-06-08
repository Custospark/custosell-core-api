<?php

namespace App\Repositories\Eloquent;

use App\Models\Expense;
use App\Repositories\Contracts\ExpenseRepositoryInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;

class ExpenseRepository implements ExpenseRepositoryInterface
{
    public function all(int $businessId, array $filters = []): LengthAwarePaginator
    {
        $query = Expense::where('business_id', $businessId)
            ->with(['expenseCategory', 'recordedBy']);

        if (!empty($filters['category_id'])) {
            $query->where('expense_category_id', $filters['category_id']);
        }
        if (!empty($filters['shift_id'])) {
            $query->where('shift_id', $filters['shift_id']);
        }
        if (!empty($filters['date_from'])) {
            $query->where('expense_date', '>=', $filters['date_from']);
        }
        if (!empty($filters['date_to'])) {
            $query->where('expense_date', '<=', $filters['date_to']);
        }

        return $query->orderBy('expense_date', 'desc')
            ->paginate($filters['per_page'] ?? 15);
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

    public function getByShift(int $businessId, int $shiftId): Collection
    {
        return Expense::where('business_id', $businessId)
            ->where('shift_id', $shiftId)
            ->with(['expenseCategory', 'recordedBy'])
            ->orderBy('expense_date', 'desc')
            ->get();
    }

    public function getSummary(int $businessId, array $filters = []): array
    {
        $query = Expense::where('business_id', $businessId);

        if (!empty($filters['date_from'])) {
            $query->where('expense_date', '>=', $filters['date_from']);
        }
        if (!empty($filters['date_to'])) {
            $query->where('expense_date', '<=', $filters['date_to']);
        }
        if (!empty($filters['category_id'])) {
            $query->where('expense_category_id', $filters['category_id']);
        }
        if (!empty($filters['shift_id'])) {
            $query->where('shift_id', $filters['shift_id']);
        }

        $totalAmount = (float) $query->sum('amount');
        $totalCount = (clone $query)->count();

        $byCategory = (clone $query)
            ->selectRaw('expense_category_id, SUM(amount) as total, COUNT(*) as count')
            ->groupBy('expense_category_id')
            ->with('expenseCategory')
            ->get()
            ->map(fn($e) => [
                'category_id' => $e->expense_category_id,
                'category_name' => $e->expenseCategory?->name ?? 'Uncategorized',
                'total' => (float) $e->total,
                'count' => (int) $e->count,
            ]);

        return [
            'total_amount' => $totalAmount,
            'total_count' => $totalCount,
            'by_category' => $byCategory,
        ];
    }
}
