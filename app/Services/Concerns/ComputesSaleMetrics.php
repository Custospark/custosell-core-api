<?php

namespace App\Services\Concerns;

use App\Models\Expense;
use App\Models\Sale;
use App\Models\SaleItem;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

trait ComputesSaleMetrics
{
    public function normalizePaymentMethod(string $method): string
    {
        return in_array($method, ['card', 'other'], true) ? 'card_other' : $method;
    }

    public function paymentMethodLabel(string $method): string
    {
        return match ($method) {
            'cash' => 'Cash',
            'mobile_money' => 'Mobile Money',
            'card_other' => 'Card / Other',
            default => ucfirst(str_replace('_', ' ', $method)),
        };
    }

    public function paymentStatusLabel(?string $status): string
    {
        return match ($status) {
            'refunded' => 'Full Refund',
            'partially_refunded' => 'Partially Refunded',
            default => 'Paid',
        };
    }

    public function saleGross(Sale $sale): float
    {
        return (float) $sale->total_amount;
    }

    public function saleRefunds(Sale $sale): float
    {
        if ($sale->relationLoaded('saleItems')) {
            return (float) $sale->saleItems->sum('refunded_amount');
        }

        return (float) SaleItem::where('sale_id', $sale->id)->sum('refunded_amount');
    }

    public function saleNetAfterRefunds(Sale $sale): float
    {
        return max(0, $this->saleGross($sale) - $this->saleRefunds($sale));
    }

    /** @deprecated Use saleNetAfterRefunds — kept for internal call sites */
    public function saleNet(Sale $sale): float
    {
        return $this->saleNetAfterRefunds($sale);
    }

    /**
     * @return array{sale: Sale, gross: float, refunds: float, net_after_refunds: float}
     */
    public function saleRow(Sale $sale): array
    {
        $gross = $this->saleGross($sale);
        $refunds = $this->saleRefunds($sale);

        return [
            'sale' => $sale,
            'gross' => $gross,
            'refunds' => $refunds,
            'net_after_refunds' => max(0, $gross - $refunds),
            'net' => max(0, $gross - $refunds),
        ];
    }

    public function applySaleFilters(Builder $query, ?int $userId, ?int $shiftId): Builder
    {
        if ($userId) {
            $query->where('user_id', $userId);
        }
        if ($shiftId) {
            $query->where('shift_id', $shiftId);
        }

        return $query;
    }

    /**
     * @return array{
     *   date: string,
     *   revenue: float,
     *   gross_sales: float,
     *   refunds: float,
     *   expenses: float,
     *   net_after_refunds: float,
     *   net_sales: float,
     *   net_revenue: float,
     *   transactions: int
     * }
     */
    public function dayMetrics(int $businessId, string $date, ?int $userId = null, ?int $shiftId = null): array
    {
        $salesQuery = Sale::where('business_id', $businessId)->whereDate('sale_date', $date);
        $this->applySaleFilters($salesQuery, $userId, $shiftId);
        $sales = $salesQuery->get();

        $gross = (float) $sales->sum('total_amount');
        $refunds = (float) SaleItem::whereIn('sale_id', $sales->pluck('id'))->sum('refunded_amount');

        $expensesQuery = Expense::where('business_id', $businessId)->whereDate('expense_date', $date);
        if ($shiftId) {
            $expensesQuery->where('shift_id', $shiftId);
        }
        $expenses = (float) $expensesQuery->sum('amount');

        $netAfterRefunds = max(0, $gross - $refunds);
        $netSales = max(0, $gross - $refunds - $expenses);

        return [
            'date' => $date,
            'revenue' => $gross,
            'gross_sales' => $gross,
            'refunds' => $refunds,
            'expenses' => $expenses,
            'net_after_refunds' => $netAfterRefunds,
            'net_sales' => $netSales,
            'net_revenue' => $netSales,
            'transactions' => $sales->count(),
        ];
    }

    /**
     * @return array{
     *   gross_sales: float,
     *   refunds: float,
     *   expenses: float,
     *   net_after_refunds: float,
     *   net_sales: float,
     *   transactions: int,
     *   refund_rate_pct: float,
     *   expense_ratio_pct: float
     * }
     */
    public function periodSummary(int $businessId, string $dateFrom, string $dateTo, ?int $userId = null, ?int $shiftId = null): array
    {
        $trend = $this->dateRangeTrend($businessId, $dateFrom, $dateTo, $userId, $shiftId);
        $totals = $this->trendTotals($trend);
        $gross = $totals['gross_sales'];

        return array_merge($totals, [
            'refund_rate_pct' => $gross > 0 ? round(($totals['refunds'] / $gross) * 100, 1) : 0.0,
            'expense_ratio_pct' => $gross > 0 ? round(($totals['expenses'] / $gross) * 100, 1) : 0.0,
        ]);
    }

    public function dateRangeTrend(int $businessId, string $dateFrom, string $dateTo, ?int $userId = null, ?int $shiftId = null): array
    {
        $trend = [];
        $current = Carbon::parse($dateFrom);
        $end = Carbon::parse($dateTo);

        while ($current->lte($end)) {
            $trend[] = $this->dayMetrics($businessId, $current->format('Y-m-d'), $userId, $shiftId);
            $current->addDay();
        }

        return $trend;
    }

    public function trendTotals(array $trend): array
    {
        $collection = collect($trend);

        return [
            'gross_sales' => (float) $collection->sum('gross_sales'),
            'refunds' => (float) $collection->sum('refunds'),
            'expenses' => (float) $collection->sum('expenses'),
            'net_after_refunds' => (float) $collection->sum('net_after_refunds'),
            'net_sales' => (float) $collection->sum('net_sales'),
            'net_revenue' => (float) $collection->sum('net_sales'),
            'transactions' => (int) $collection->sum('transactions'),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function buildTrendInsights(array $trend, array $totals): array
    {
        if (empty($trend)) {
            return [
                'best_day' => null,
                'worst_day' => null,
                'avg_net_sales' => 0.0,
                'avg_transactions' => 0.0,
                'refund_rate_pct' => 0.0,
                'expense_ratio_pct' => 0.0,
            ];
        }

        $best = collect($trend)->sortByDesc('net_sales')->first();
        $worst = collect($trend)->sortBy('net_sales')->first();
        $days = count($trend);
        $gross = $totals['gross_sales'];

        return [
            'best_day' => $best,
            'worst_day' => $worst,
            'avg_net_sales' => $days > 0 ? round($totals['net_sales'] / $days, 2) : 0.0,
            'avg_transactions' => $days > 0 ? round($totals['transactions'] / $days, 1) : 0.0,
            'refund_rate_pct' => $gross > 0 ? round(($totals['refunds'] / $gross) * 100, 1) : 0.0,
            'expense_ratio_pct' => $gross > 0 ? round(($totals['expenses'] / $gross) * 100, 1) : 0.0,
        ];
    }

    public function paymentBreakdown(int $businessId, string $dateFrom, string $dateTo, ?int $userId = null, ?int $shiftId = null): array
    {
        $query = Sale::where('business_id', $businessId)
            ->whereDate('sale_date', '>=', $dateFrom)
            ->whereDate('sale_date', '<=', $dateTo)
            ->with('saleItems');
        $this->applySaleFilters($query, $userId, $shiftId);
        $sales = $query->get();

        $groups = [];
        foreach ($sales as $sale) {
            $method = $this->normalizePaymentMethod($sale->payment_method);
            if (! isset($groups[$method])) {
                $groups[$method] = [
                    'method' => $method,
                    'label' => $this->paymentMethodLabel($method),
                    'count' => 0,
                    'gross' => 0.0,
                    'refunds' => 0.0,
                    'net' => 0.0,
                ];
            }
            $row = $this->saleRow($sale);
            $groups[$method]['count']++;
            $groups[$method]['gross'] += $row['gross'];
            $groups[$method]['refunds'] += $row['refunds'];
            $groups[$method]['net'] += $row['net_after_refunds'];
        }

        $result = array_values($groups);
        $totalNet = collect($result)->sum('net');
        foreach ($result as &$group) {
            $group['share_pct'] = $totalNet > 0 ? round(($group['net'] / $totalNet) * 100, 1) : 0.0;
        }

        return $result;
    }

    public function expenseCategorySummary(Collection $expenses): array
    {
        return $expenses
            ->groupBy(fn ($expense) => $expense->expense_category_id ?? 0)
            ->map(function (Collection $group) {
                $first = $group->first();

                return [
                    'category_id' => $first->expense_category_id,
                    'category_name' => $first->expenseCategory?->name ?? 'Uncategorized',
                    'count' => $group->count(),
                    'total' => (float) $group->sum('amount'),
                ];
            })
            ->sortByDesc('total')
            ->values()
            ->all();
    }
}
