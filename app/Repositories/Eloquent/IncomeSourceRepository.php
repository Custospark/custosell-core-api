<?php

namespace App\Repositories\Eloquent;

use App\Models\IncomeSource;
use App\Repositories\Contracts\IncomeSourceRepositoryInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;

class IncomeSourceRepository implements IncomeSourceRepositoryInterface
{
    public function all(int $businessId, array $filters = []): LengthAwarePaginator
    {
        $query = IncomeSource::with('attachments')->where('business_id', $businessId);

        if (!empty($filters['source_name'])) {
            $query->where('source_name', $filters['source_name']);
        }
        if (!empty($filters['date_from'])) {
            $query->where('income_date', '>=', $filters['date_from']);
        }
        if (!empty($filters['date_to'])) {
            $query->where('income_date', '<=', $filters['date_to']);
        }

        return $query->orderBy('income_date', 'desc')
            ->paginate($filters['per_page'] ?? 15);
    }

    public function find(int $id): ?IncomeSource
    {
        return IncomeSource::with('attachments')->find($id);
    }

    public function create(array $data): IncomeSource
    {
        return IncomeSource::create($data);
    }

    public function update(IncomeSource $incomeSource, array $data): IncomeSource
    {
        $incomeSource->update($data);
        return $incomeSource->fresh();
    }

    public function delete(IncomeSource $incomeSource): bool
    {
        return $incomeSource->delete();
    }

    public function getByDateRange(int $businessId, string $start, string $end): Collection
    {
        return IncomeSource::where('business_id', $businessId)
            ->whereBetween('income_date', [$start, $end])
            ->orderBy('income_date', 'desc')
            ->get();
    }

    public function getSummary(int $businessId, array $filters = []): array
    {
        $query = IncomeSource::where('business_id', $businessId);

        if (!empty($filters['date_from'])) {
            $query->where('income_date', '>=', $filters['date_from']);
        }
        if (!empty($filters['date_to'])) {
            $query->where('income_date', '<=', $filters['date_to']);
        }
        if (!empty($filters['source_name'])) {
            $query->where('source_name', $filters['source_name']);
        }

        $totalAmount = (float) $query->sum('amount');
        $totalCount = (clone $query)->count();

        $bySource = (clone $query)
            ->selectRaw('source_name, SUM(amount) as total, COUNT(*) as count')
            ->groupBy('source_name')
            ->get()
            ->map(fn($e) => [
                'source' => $e->source_name,
                'total' => (float) $e->total,
                'count' => (int) $e->count,
            ]);

        return [
            'total_amount' => $totalAmount,
            'total_count' => $totalCount,
            'by_source' => $bySource,
        ];
    }
}
