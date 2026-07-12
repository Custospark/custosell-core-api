<?php

namespace App\Services;

use App\Models\Business;
use App\Models\Customer;
use App\Models\Expense;
use App\Models\Product;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\Shift;
use App\Support\TaxEngine;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Canonical accounting:
 * - net_sales (period/day) = gross − refunds − expenses
 * - net_after_refunds = gross − refunds (per sale, shift sales headline)
 */
class ReportMetricsService
{
    public const BRAND_TAGLINE = 'Your Business Operating System';

    public const BRAND_FOOTER = 'Powered by Custosell · A product of Custospark Company Ltd';

    public const BRAND_CUSTOSELL_URL = 'https://www.custosell.com';

    public const BRAND_CUSTOSPARK_URL = 'https://www.custospark.com';

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

    public function productPerformance(int $businessId, string $dateFrom, string $dateTo, ?int $userId = null): array
    {
        $query = DB::table('sale_items')
            ->join('sales', 'sale_items.sale_id', '=', 'sales.id')
            ->where('sales.business_id', $businessId)
            ->whereDate('sales.sale_date', '>=', $dateFrom)
            ->whereDate('sales.sale_date', '<=', $dateTo)
            ->whereNull('sales.deleted_at');

        if ($userId) {
            $query->where('sales.user_id', $userId);
        }

        $rows = $query
            ->selectRaw('sale_items.product_name as product_name')
            ->selectRaw('SUM(sale_items.quantity) as quantity_sold')
            ->selectRaw('SUM(sale_items.subtotal) as gross_revenue')
            ->selectRaw('SUM(sale_items.refunded_amount) as refunds')
            ->selectRaw('SUM(sale_items.subtotal) - SUM(sale_items.refunded_amount) as net_after_refunds')
            ->groupBy('sale_items.product_name')
            ->orderByDesc('net_after_refunds')
            ->get();

        return $rows->map(fn ($row) => [
            'product_name' => $row->product_name,
            'quantity_sold' => (int) $row->quantity_sold,
            'gross_revenue' => (float) $row->gross_revenue,
            'refunds' => (float) $row->refunds,
            'net_after_refunds' => max(0, (float) $row->net_after_refunds),
        ])->all();
    }

    /**
     * @return array{
     *   products: list<array{product_name: string, quantity_sold: int, gross_revenue: float, refunds: float, net_after_refunds: float}>,
     *   top_by_net: list<array{product_name: string, quantity_sold: int, gross_revenue: float, refunds: float, net_after_refunds: float}>,
     *   top_by_quantity: list<array{product_name: string, quantity_sold: int, gross_revenue: float, refunds: float, net_after_refunds: float}>,
     *   slowest_sold: list<array{product_name: string, quantity_sold: int, gross_revenue: float, refunds: float, net_after_refunds: float}>,
     *   no_sales: list<array{product_name: string, quantity_sold: int, gross_revenue: float, refunds: float, net_after_refunds: float}>,
     *   no_sales_count: int
     * }
     */
    public function productPerformanceReport(int $businessId, string $dateFrom, string $dateTo, ?int $userId = null): array
    {
        $products = $this->productPerformance($businessId, $dateFrom, $dateTo, $userId);

        $soldProductIds = DB::table('sale_items')
            ->join('sales', 'sale_items.sale_id', '=', 'sales.id')
            ->where('sales.business_id', $businessId)
            ->whereDate('sales.sale_date', '>=', $dateFrom)
            ->whereDate('sales.sale_date', '<=', $dateTo)
            ->whereNull('sales.deleted_at')
            ->whereNotNull('sale_items.product_id')
            ->when($userId, fn ($q) => $q->where('sales.user_id', $userId))
            ->distinct()
            ->pluck('sale_items.product_id');

        $soldNames = collect($products)
            ->map(fn ($product) => mb_strtolower(trim($product['product_name'])))
            ->flip();

        $noSales = Product::query()
            ->where('business_id', $businessId)
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name'])
            ->filter(function (Product $product) use ($soldProductIds, $soldNames) {
                if ($soldProductIds->contains($product->id)) {
                    return false;
                }

                return ! isset($soldNames[mb_strtolower(trim($product->name))]);
            })
            ->map(fn (Product $product) => [
                'product_name' => $product->name,
                'quantity_sold' => 0,
                'gross_revenue' => 0.0,
                'refunds' => 0.0,
                'net_after_refunds' => 0.0,
            ])
            ->values()
            ->all();

        return [
            'products' => $products,
            'top_by_net' => array_slice($products, 0, 5),
            'top_by_quantity' => collect($products)->sortByDesc('quantity_sold')->take(5)->values()->all(),
            'slowest_sold' => collect($products)
                ->sortBy(fn ($product) => [$product['quantity_sold'], $product['net_after_refunds']])
                ->take(5)
                ->values()
                ->all(),
            'no_sales' => $noSales,
            'no_sales_count' => count($noSales),
        ];
    }

    /** @return list<string> */
    public function productPerformanceInsightLines(array $report, string $currency, ReportExportService $formatter): array
    {
        $lines = [];

        foreach ($report['top_by_net'] as $index => $product) {
            $lines[] = 'Top net #'.($index + 1).': '.$product['product_name']
                .' - '.$formatter->formatMoney($product['net_after_refunds'], $currency)
                .' ('.$product['quantity_sold'].' units)';
        }

        foreach ($report['top_by_quantity'] as $index => $product) {
            $lines[] = 'Top quantity #'.($index + 1).': '.$product['product_name']
                .' - '.$product['quantity_sold'].' units'
                .' ('.$formatter->formatMoney($product['net_after_refunds'], $currency).' net)';
        }

        foreach ($report['slowest_sold'] as $product) {
            if ($product['quantity_sold'] <= 0) {
                continue;
            }
            $lines[] = 'Slow mover: '.$product['product_name']
                .' - '.$product['quantity_sold'].' units'
                .' ('.$formatter->formatMoney($product['net_after_refunds'], $currency).' net)';
        }

        if ($report['no_sales_count'] > 0) {
            $preview = collect($report['no_sales'])->take(5)->pluck('product_name')->implode(', ');
            $lines[] = 'No sales this period: '.$report['no_sales_count'].' active product(s)'
                .($preview ? ' (e.g. '.$preview.')' : '');
        }

        return $lines;
    }

    public function shiftReconciliation(int $businessId, string $dateFrom, string $dateTo, ?int $shiftId = null, ?int $userId = null): array
    {
        $query = Shift::where('business_id', $businessId)
            ->whereDate('clock_in', '>=', $dateFrom)
            ->whereDate('clock_in', '<=', $dateTo)
            ->with('user')
            ->orderByDesc('clock_in');

        if ($shiftId) {
            $query->where('id', $shiftId);
        }
        if ($userId) {
            $query->where('user_id', $userId);
        }

        return $query->get()->map(function (Shift $shift) use ($businessId) {
            $sales = Sale::where('business_id', $businessId)
                ->where('shift_id', $shift->id)
                ->with('saleItems')
                ->get();

            $gross = (float) $sales->sum('total_amount');
            $refunds = (float) SaleItem::whereIn('sale_id', $sales->pluck('id'))->sum('refunded_amount');
            $netAfterRefunds = max(0, $gross - $refunds);

            $cash = 0.0;
            $mobile = 0.0;
            $cardOther = 0.0;
            foreach ($sales as $sale) {
                $net = $this->saleNetAfterRefunds($sale);
                $method = $this->normalizePaymentMethod($sale->payment_method);
                match ($method) {
                    'cash' => $cash += $net,
                    'mobile_money' => $mobile += $net,
                    default => $cardOther += $net,
                };
            }

            $shiftExpenses = (float) Expense::where('business_id', $businessId)
                ->where('shift_id', $shift->id)
                ->sum('amount');
            $netSales = max(0, $gross - $refunds - $shiftExpenses);
            $cashHandover = max(0, $cash - $shiftExpenses);

            return [
                'shift' => $shift,
                'cashier' => $shift->user?->name ?? '—',
                'transaction_count' => $sales->count(),
                'gross_sales' => $gross,
                'refunds' => $refunds,
                'net_after_refunds' => $netAfterRefunds,
                'net_sales' => $netSales,
                'shift_expenses' => $shiftExpenses,
                'cash' => $cash,
                'mobile_money' => $mobile,
                'card_other' => $cardOther,
                'cash_handover' => $cashHandover,
            ];
        })->all();
    }

    /**
     * Single-shift close report for cashier handover (PDF).
     *
     * @return array{
     *   shift: Shift,
     *   cashier: string,
     *   duration: string|null,
     *   transaction_count: int,
     *   gross_sales: float,
     *   refunds: float,
     *   net_after_refunds: float,
     *   net_sales: float,
     *   shift_expenses: float,
     *   cash: float,
     *   mobile_money: float,
     *   card_other: float,
     *   cash_handover: float
     * }
     */
    public function shiftCloseReport(int $businessId, int $shiftId): array
    {
        $shift = Shift::where('business_id', $businessId)
            ->where('id', $shiftId)
            ->with('user')
            ->firstOrFail();

        $reconciliation = $this->shiftReconciliation(
            $businessId,
            $shift->clock_in->format('Y-m-d'),
            $shift->clock_in->format('Y-m-d'),
            $shiftId,
            null,
        );

        $metrics = $reconciliation[0] ?? [
            'transaction_count' => 0,
            'gross_sales' => 0.0,
            'refunds' => 0.0,
            'net_after_refunds' => 0.0,
            'net_sales' => 0.0,
            'shift_expenses' => 0.0,
            'cash' => 0.0,
            'mobile_money' => 0.0,
            'card_other' => 0.0,
            'cash_handover' => 0.0,
        ];

        return [
            'shift' => $shift,
            'cashier' => $shift->user?->name ?? '—',
            'duration' => $this->formatShiftDuration($shift),
            'transaction_count' => (int) $metrics['transaction_count'],
            'gross_sales' => (float) $metrics['gross_sales'],
            'refunds' => (float) $metrics['refunds'],
            'net_after_refunds' => (float) $metrics['net_after_refunds'],
            'net_sales' => (float) $metrics['net_sales'],
            'shift_expenses' => (float) $metrics['shift_expenses'],
            'cash' => (float) $metrics['cash'],
            'mobile_money' => (float) $metrics['mobile_money'],
            'card_other' => (float) $metrics['card_other'],
            'cash_handover' => (float) $metrics['cash_handover'],
        ];
    }

    public function formatShiftDuration(Shift $shift): ?string
    {
        if (! $shift->clock_out) {
            return null;
        }

        $minutes = $shift->clock_in->diffInMinutes($shift->clock_out);
        $hours = intdiv($minutes, 60);
        $mins = $minutes % 60;

        if ($hours > 0) {
            return "{$hours}h {$mins}m";
        }

        return "{$mins}m";
    }

    public function plSummaryCards(array $summary, string $currency, ReportExportService $formatter): array
    {
        return [
            ['label' => 'Gross Sales', 'value' => $formatter->formatMoney($summary['gross_sales'], $currency)],
            ['label' => 'Refunds', 'value' => '-'.$formatter->formatMoney($summary['refunds'], $currency), 'tone' => 'negative'],
            ['label' => 'Expenses', 'value' => '-'.$formatter->formatMoney($summary['expenses'], $currency), 'tone' => 'negative'],
            ['label' => 'Net Sales', 'value' => $formatter->formatMoney($summary['net_sales'], $currency), 'tone' => 'positive'],
        ];
    }

    /** @return list<string> */
    public function insightLines(array $insights, string $currency, ReportExportService $formatter): array
    {
        $lines = [];

        if (! empty($insights['best_day'])) {
            $lines[] = 'Best day: '.$insights['best_day']['date'].' - '.$formatter->formatMoney($insights['best_day']['net_sales'], $currency).' net sales';
        }
        if (! empty($insights['worst_day'])) {
            $lines[] = 'Weakest day: '.$insights['worst_day']['date'].' - '.$formatter->formatMoney($insights['worst_day']['net_sales'], $currency).' net sales';
        }
        if (isset($insights['refund_rate_pct'], $insights['expense_ratio_pct'])) {
            $lines[] = 'Refund rate: '.$insights['refund_rate_pct'].'% | Expense ratio: '.$insights['expense_ratio_pct'].'% of gross';
        }
        if (isset($insights['avg_net_sales'])) {
            $lines[] = 'Average daily net sales: '.$formatter->formatMoney((float) $insights['avg_net_sales'], $currency);
        }

        return $lines;
    }

    public function dashboardSummary(int $businessId): array
    {
        $today = now()->format('Y-m-d');
        $todayMetrics = $this->dayMetrics($businessId, $today);
        $business = Business::find($businessId);
        $taxEngine = app(TaxEngine::class);
        $todayVatPayload = null;
        if ($business && $taxEngine->isTaxEnabled($business)) {
            $todayVat = $this->vatSummary($businessId, $today, $today);
            $todayVatPayload = [
                'output_vat' => $todayVat['output_vat'],
                'output_vat_refunded' => $todayVat['output_vat_refunded'],
                'net_output_vat' => $todayVat['net_output_vat'],
                'input_vat' => $todayVat['input_vat'],
                'vat_payable' => $todayVat['vat_payable'],
                'transaction_count' => $todayVat['transaction_count'],
            ];
        }

        $todaySales = Sale::where('business_id', $businessId)->whereDate('sale_date', $today)->get();
        $todayProductsSold = (int) SaleItem::whereIn('sale_id', $todaySales->pluck('id'))->sum('quantity');

        $salesTrend = [];
        for ($i = 6; $i >= 0; $i--) {
            $date = now()->subDays($i)->format('Y-m-d');
            $day = $this->dayMetrics($businessId, $date);
            $salesTrend[] = [
                'date' => $day['date'],
                'revenue' => $day['revenue'],
                'refunds' => $day['refunds'],
                'expenses' => $day['expenses'],
                'net_after_refunds' => $day['net_after_refunds'],
                'net_sales' => $day['net_sales'],
                'net_revenue' => $day['net_sales'],
                'transactions' => $day['transactions'],
            ];
        }

        $lowStock = Product::where('business_id', $businessId)
            ->where('is_active', true)
            ->where(function ($q) {
                $q->where('type', Product::TYPE_PRODUCT)
                    ->orWhereNull('type');
            })
            ->whereColumn('stock_quantity', '<=', 'low_stock_threshold')
            ->get(['id', 'name', 'stock_quantity', 'low_stock_threshold']);

        $recentSales = Sale::where('business_id', $businessId)
            ->with('saleItems')
            ->orderBy('created_at', 'desc')
            ->limit(5)
            ->get()
            ->map(function (Sale $sale) {
                $refunds = $this->saleRefunds($sale);
                $gross = $this->saleGross($sale);

                return [
                    'id' => $sale->id,
                    'receipt_number' => $sale->receipt_number,
                    'total_amount' => $gross,
                    'refunds' => $refunds,
                    'net_amount' => max(0, $gross - $refunds),
                    'payment_method' => $sale->payment_method,
                    'created_at' => $sale->created_at,
                    'items_count' => $sale->saleItems->count(),
                ];
            });

        return [
            'today_revenue' => $todayMetrics['revenue'],
            'today_gross_sales' => $todayMetrics['gross_sales'],
            'today_refunds' => $todayMetrics['refunds'],
            'today_net_after_refunds' => $todayMetrics['net_after_refunds'],
            'today_net_sales' => $todayMetrics['net_sales'],
            'today_transactions' => $todayMetrics['transactions'],
            'today_products_sold' => $todayProductsSold,
            'today_expenses' => $todayMetrics['expenses'],
            'today_net_after_expenses' => $todayMetrics['net_sales'],
            'active_products' => Product::where('business_id', $businessId)->where('is_active', true)->count(),
            'total_customers' => Customer::where('business_id', $businessId)->count(),
            'sales_trend' => $salesTrend,
            'low_stock' => $lowStock,
            'recent_sales' => $recentSales,
            'today_vat' => $todayVatPayload,
        ];
    }

    /** VAT return workbook metrics for a date range. */
    public function vatSummary(int $businessId, string $dateFrom, string $dateTo): array
    {
        $saleIds = Sale::query()
            ->where('business_id', $businessId)
            ->whereDate('sale_date', '>=', $dateFrom)
            ->whereDate('sale_date', '<=', $dateTo)
            ->pluck('id');

        $items = SaleItem::query()
            ->whereIn('sale_id', $saleIds)
            ->with('product')
            ->get();

        $outputVat = 0.0;
        $outputVatRefunded = 0.0;
        $taxableSales = 0.0;
        $exemptSales = 0.0;
        $zeroRatedSales = 0.0;

        foreach ($items as $item) {
            $net = (float) $item->subtotal;
            $taxClass = $item->product?->tax_class ?? TaxEngine::CLASS_STANDARD;

            $outputVat += (float) $item->tax_amount;
            $outputVatRefunded += (float) $item->tax_refunded_amount;

            if ($taxClass === TaxEngine::CLASS_EXEMPT) {
                $exemptSales += $net;
            } elseif ($taxClass === TaxEngine::CLASS_ZERO_RATED) {
                $zeroRatedSales += $net;
            } else {
                $taxableSales += $net;
            }
        }

        $claimableExpenses = Expense::query()
            ->where('business_id', $businessId)
            ->whereDate('expense_date', '>=', $dateFrom)
            ->whereDate('expense_date', '<=', $dateTo)
            ->where('vat_claimable', true);

        $inputVat = (float) (clone $claimableExpenses)->sum('vat_amount');
        $claimableExpenseTotal = (float) $claimableExpenses->sum('amount');
        $netOutputVat = max(0, $outputVat - $outputVatRefunded);
        $vatPayable = $netOutputVat - $inputVat;

        return [
            'output_vat' => round($outputVat, 2),
            'output_vat_refunded' => round($outputVatRefunded, 2),
            'net_output_vat' => round($netOutputVat, 2),
            'input_vat' => round($inputVat, 2),
            'vat_payable' => round($vatPayable, 2),
            'taxable_sales_net' => round($taxableSales, 2),
            'exempt_sales_net' => round($exemptSales, 2),
            'zero_rated_sales_net' => round($zeroRatedSales, 2),
            'claimable_expense_total' => round($claimableExpenseTotal, 2),
            'transaction_count' => $saleIds->count(),
        ];
    }

    /** @return list<array<string, mixed>> */
    public function vatInputExpenseRows(int $businessId, string $dateFrom, string $dateTo): array
    {
        return Expense::query()
            ->where('business_id', $businessId)
            ->whereDate('expense_date', '>=', $dateFrom)
            ->whereDate('expense_date', '<=', $dateTo)
            ->where('vat_claimable', true)
            ->with('expenseCategory')
            ->orderBy('expense_date')
            ->get()
            ->map(fn (Expense $expense) => [
                'date' => $expense->expense_date?->format('Y-m-d'),
                'category' => $expense->expenseCategory?->name ?? '—',
                'description' => $expense->description,
                'supplier_tin' => $expense->supplier_tin,
                'supplier_invoice_no' => $expense->supplier_invoice_no,
                'amount' => (float) $expense->amount,
                'vat_amount' => (float) ($expense->vat_amount ?? 0),
            ])
            ->all();
    }
}
