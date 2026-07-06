<?php

namespace App\Repositories\Eloquent;

use App\Models\Estimate;
use App\Models\EstimateTemplate;
use App\Repositories\Contracts\EstimateRepositoryInterface;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

class EstimateRepository implements EstimateRepositoryInterface
{
    public function all(int $businessId, array $filters = []): Collection
    {
        $query = Estimate::where('business_id', $businessId)
            ->with(['customer', 'createdBy', 'assignedTo', 'lineItems.product']);

        if (!empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }
        if (!empty($filters['customer_id'])) {
            $query->where('customer_id', $filters['customer_id']);
        }
        if (!empty($filters['date_from'])) {
            $query->where('created_at', '>=', $filters['date_from']);
        }
        if (!empty($filters['date_to'])) {
            $query->where('created_at', '<=', $filters['date_to']);
        }

        return $query->orderByDesc('created_at')->get();
    }

    public function find(int $id): ?Estimate
    {
        return Estimate::with([
            'customer',
            'createdBy',
            'assignedTo',
            'lineItems.product',
            'versions.createdBy',
            'project',
            'invoice',
        ])->find($id);
    }

    public function findByNumber(int $businessId, string $number): ?Estimate
    {
        return Estimate::where('business_id', $businessId)
            ->where('estimate_number', $number)
            ->with(['customer', 'lineItems.product'])
            ->first();
    }

    public function create(array $data): Estimate
    {
        return Estimate::create($data);
    }

    public function update(Estimate $estimate, array $data): Estimate
    {
        $estimate->update($data);

        return $estimate->fresh();
    }

    public function delete(Estimate $estimate): bool
    {
        return $estimate->delete();
    }

    public function templates(int $businessId): Collection
    {
        return EstimateTemplate::where('business_id', $businessId)
            ->where('is_active', true)
            ->orderBy('name')
            ->get();
    }

    public function findTemplate(int $id): ?EstimateTemplate
    {
        return EstimateTemplate::find($id);
    }

    public function createTemplate(array $data): EstimateTemplate
    {
        return EstimateTemplate::create($data);
    }

    public function updateTemplate(EstimateTemplate $template, array $data): EstimateTemplate
    {
        $template->update($data);

        return $template->fresh();
    }

    public function deleteTemplate(EstimateTemplate $template): bool
    {
        return $template->delete();
    }

    public function analyticsSummary(int $businessId): array
    {
        $rows = Estimate::query()
            ->where('business_id', $businessId)
            ->select('status', DB::raw('COUNT(*) as count'), DB::raw('SUM(total) as total_value'))
            ->groupBy('status')
            ->get();

        $byStatus = [];
        $totalCount = 0;
        $totalValue = 0;

        foreach ($rows as $row) {
            $byStatus[$row->status] = [
                'count' => (int) $row->count,
                'total_value' => round((float) $row->total_value, 2),
            ];
            $totalCount += (int) $row->count;
            $totalValue += (float) $row->total_value;
        }

        $approved = Estimate::query()
            ->where('business_id', $businessId)
            ->where('status', 'approved')
            ->count();

        $converted = Estimate::query()
            ->where('business_id', $businessId)
            ->whereNotNull('invoice_id')
            ->count();

        return [
            'total_count' => $totalCount,
            'total_value' => round($totalValue, 2),
            'by_status' => $byStatus,
            'approved_count' => $approved,
            'converted_to_invoice_count' => $converted,
            'conversion_rate' => $totalCount > 0
                ? round(($converted / $totalCount) * 100, 2)
                : 0,
        ];
    }
}
