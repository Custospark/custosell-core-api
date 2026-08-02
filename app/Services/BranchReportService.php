<?php

namespace App\Services;

use App\Models\Location;
use App\Models\Sale;
use App\Models\SaleItem;

/**
 * Branch (location) performance breakdown used by the dashboard graph and the
 * business reports. Groups sales by the location they were recorded in.
 */
class BranchReportService
{
    /**
     * @return array{date_from: string, date_to: string, branches: list<array{
     *   location_id: int, name: string, is_default: bool,
     *   gross_sales: float, refunds: float, net_after_refunds: float,
     *   expenses: float, net_sales: float, transactions: int,
     *   items_sold: int, share_pct: float
     * }>}
     */
    public function performance(int $businessId, string $dateFrom, string $dateTo, ?int $userId = null, ?int $shiftId = null): array
    {
        $salesQuery = Sale::with('saleItems')
            ->where('business_id', $businessId)
            ->whereDate('sale_date', '>=', $dateFrom)
            ->whereDate('sale_date', '<=', $dateTo);

        if ($userId) {
            $salesQuery->where('user_id', $userId);
        }
        if ($shiftId) {
            $salesQuery->where('shift_id', $shiftId);
        }

        $sales = $salesQuery->get()->groupBy('location_id');

        $locations = Location::forBusiness($businessId)->get()->keyBy('id');

        $rows = [];
        foreach ($sales as $locationId => $group) {
            if ((int) $locationId === 0) {
                continue;
            }
            $location = $locations->get((int) $locationId);
            $gross = (float) $group->sum('total_amount');
            $refunds = (float) $group->sum(function ($sale) {
                return $sale->saleItems->sum('refunded_amount');
            });
            $itemsSold = (int) $group->sum(function ($sale) {
                return $sale->saleItems->count();
            });

            $rows[] = [
                'location_id' => (int) $locationId,
                'name' => $location?->name ?? 'Unknown Branch',
                'is_default' => (bool) ($location?->is_default ?? false),
                'gross_sales' => $gross,
                'refunds' => $refunds,
                'net_after_refunds' => max(0, $gross - $refunds),
                'expenses' => 0.0,
                'net_sales' => max(0, $gross - $refunds),
                'transactions' => $group->count(),
                'items_sold' => $itemsSold,
                'share_pct' => 0.0,
            ];
        }

        $totalNet = (float) collect($rows)->sum('net_after_refunds');
        foreach ($rows as &$row) {
            $row['share_pct'] = $totalNet > 0 ? round(($row['net_after_refunds'] / $totalNet) * 100, 1) : 0.0;
        }

        return [
            'date_from' => $dateFrom,
            'date_to' => $dateTo,
            'branches' => $rows,
        ];
    }
}