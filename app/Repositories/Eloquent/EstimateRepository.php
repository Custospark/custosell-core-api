<?php

namespace App\Repositories\Eloquent;

use App\Models\Estimate;
use App\Models\EstimateTemplate;
use App\Repositories\Contracts\EstimateRepositoryInterface;
use Carbon\Carbon;
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
            'invoice.payments',
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
        $estimates = Estimate::query()
            ->where('business_id', $businessId)
            ->get();

        $statusCounts = [
            'draft' => 0,
            'sent' => 0,
            'approved' => 0,
            'rejected' => 0,
            'expired' => 0,
            'converted' => 0,
        ];

        $byStatus = [];
        $totalPipelineValue = 0;
        $totalApprovedValue = 0;
        $totalCost = 0;
        $totalGrossProfit = 0;
        $marginSum = 0;
        $today = Carbon::today();

        foreach ($estimates as $estimate) {
            $status = (string) $estimate->status;
            if ($estimate->valid_until
                && $estimate->valid_until->lt($today)
                && !in_array($status, ['approved', 'rejected', 'converted'], true)) {
                $status = 'expired';
            }

            $statusCounts[$status] = ($statusCounts[$status] ?? 0) + 1;

            if (!isset($byStatus[$status])) {
                $byStatus[$status] = ['status' => $status, 'count' => 0, 'total' => 0];
            }
            $byStatus[$status]['count']++;
            $byStatus[$status]['total'] = round($byStatus[$status]['total'] + (float) $estimate->total, 2);

            if (in_array($status, ['draft', 'sent'], true)) {
                $totalPipelineValue += (float) $estimate->total;
            }
            if ($status === 'approved' || $estimate->status === 'approved') {
                $totalApprovedValue += (float) $estimate->total;
            }

            $totalCost += (float) $estimate->cost_subtotal;
            $totalGrossProfit += (float) $estimate->gross_profit;
            $marginSum += (float) $estimate->margin_percent;
        }

        $approvedCount = $statusCounts['approved'] + $statusCounts['converted'];
        $rejectedCount = $statusCounts['rejected'];
        $decided = $approvedCount + $rejectedCount;
        $winRate = $decided > 0 ? round(($approvedCount / $decided) * 100, 2) : 0;

        $monthRows = Estimate::query()
            ->where('business_id', $businessId)
            ->whereNotNull('sent_at')
            ->selectRaw("DATE_FORMAT(sent_at, '%Y-%m') as month")
            ->selectRaw("SUM(CASE WHEN status IN ('sent','approved','converted','rejected') THEN 1 ELSE 0 END) as sent")
            ->selectRaw("SUM(CASE WHEN status IN ('approved','converted') THEN 1 ELSE 0 END) as approved")
            ->selectRaw("SUM(CASE WHEN status = 'rejected' THEN 1 ELSE 0 END) as rejected")
            ->selectRaw('SUM(total) as value')
            ->groupBy('month')
            ->orderBy('month')
            ->limit(12)
            ->get();

        $byMonth = $monthRows->map(fn ($row) => [
            'month' => $row->month,
            'sent' => (int) $row->sent,
            'approved' => (int) $row->approved,
            'rejected' => (int) $row->rejected,
            'value' => round((float) $row->value, 2),
        ])->values()->all();

        return [
            'total_estimates' => $estimates->count(),
            'draft_count' => $statusCounts['draft'],
            'sent_count' => $statusCounts['sent'],
            'approved_count' => $statusCounts['approved'],
            'rejected_count' => $rejectedCount,
            'expired_count' => $statusCounts['expired'],
            'converted_count' => $statusCounts['converted'],
            'win_rate' => $winRate,
            'avg_margin_percent' => $estimates->count() > 0
                ? round($marginSum / $estimates->count(), 2)
                : 0,
            'total_pipeline_value' => round($totalPipelineValue, 2),
            'total_approved_value' => round($totalApprovedValue, 2),
            'total_cost' => round($totalCost, 2),
            'total_gross_profit' => round($totalGrossProfit, 2),
            'by_status' => array_values($byStatus),
            'by_month' => $byMonth,
        ];
    }
}
