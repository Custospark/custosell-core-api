<?php

namespace App\Services;

use App\Models\Expense;
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

        $expenseQuery = Expense::selectRaw('location_id, SUM(amount) as total')
            ->where('business_id', $businessId)
            ->whereDate('expense_date', '>=', $dateFrom)
            ->whereDate('expense_date', '<=', $dateTo)
            ->whereNotNull('location_id')
            ->groupBy('location_id');

        if ($userId) {
            $expenseQuery->where('recorded_by', $userId);
        }
        if ($shiftId) {
            $expenseQuery->where('shift_id', $shiftId);
        }

        $expenseTotals = $expenseQuery->get()->keyBy('location_id');

        $locations = Location::forBusiness($businessId)->get()->keyBy('id');

        $branchIds = collect($sales->keys())
            ->merge($expenseTotals->keys())
            ->filter(fn($id) => (int) $id !== 0)
            ->unique()
            ->values();

        $rows = [];
        foreach ($branchIds as $branchId) {
            $locationId = (int) $branchId;
            $group = collect($sales->get($locationId, []));
            $location = $locations->get($locationId);
            $gross = (float) $group->sum('total_amount');
            $refunds = (float) $group->sum(function ($sale) {
                return $sale->saleItems->sum('refunded_amount');
            });
            $expenses = (float) ($expenseTotals->get($locationId)->total ?? 0);
            $itemsSold = (int) $group->sum(function ($sale) {
                return $sale->saleItems->count();
            });

            $rows[] = [
                'location_id' => $locationId,
                'name' => $location?->name ?? 'Unknown Branch',
                'is_default' => (bool) ($location?->is_default ?? false),
                'gross_sales' => $gross,
                'refunds' => $refunds,
                'net_after_refunds' => max(0, $gross - $refunds),
                'expenses' => $expenses,
                'net_sales' => max(0, $gross - $refunds - $expenses),
                'transactions' => $group->count(),
                'items_sold' => $itemsSold,
                'share_pct' => 0.0,
            ];
        }

        $totalNet = (float) collect($rows)->sum('net_sales');
        foreach ($rows as &$row) {
            $row['share_pct'] = $totalNet > 0 ? round(($row['net_sales'] / $totalNet) * 100, 1) : 0.0;
        }

        return [
            'date_from' => $dateFrom,
            'date_to' => $dateTo,
            'branches' => $rows,
        ];
    }
}