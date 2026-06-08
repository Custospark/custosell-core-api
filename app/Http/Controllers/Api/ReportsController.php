<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Business;
use App\Models\Expense;
use App\Models\Product;
use App\Models\Sale;
use App\Models\Shift;
use App\Support\ReportDateRange;
use App\Services\ReportExportService;
use App\Services\ReportMetricsService;
use Illuminate\Http\Request;

class ReportsController extends Controller
{
    public function __construct(
        private ReportMetricsService $metrics,
        private ReportExportService $export,
    ) {}

    private function getDateRange(Request $request): array
    {
        return ReportDateRange::fromRequest(
            $request->query('date_from'),
            $request->query('date_to'),
        );
    }

    private function businessId(Request $request): int
    {
        return (int) $request->user()->business_id;
    }

    private function getBusiness(Request $request): Business
    {
        return Business::findOrFail($this->businessId($request));
    }

    /** @return array{user_id: int|null, shift_id: int|null} */
    private function filters(Request $request): array
    {
        return [
            'user_id' => $request->filled('user_id') ? (int) $request->query('user_id') : null,
            'shift_id' => $request->filled('shift_id') ? (int) $request->query('shift_id') : null,
        ];
    }

    private function pdfData(Request $request, array $extra = []): array
    {
        return array_merge([
            'business' => $this->getBusiness($request),
            'formatter' => $this->export,
            'metrics' => $this->metrics,
            'brandTagline' => ReportMetricsService::BRAND_TAGLINE,
            'brandFooter' => ReportMetricsService::BRAND_FOOTER,
        ], $extra);
    }

    private function dateSubtitle(string $dateFrom, string $dateTo): string
    {
        return "{$dateFrom} - {$dateTo}";
    }

    /** @return list<list<mixed>> */
    private function trendExportRows(array $trend): array
    {
        return array_map(fn ($day) => [
            $day['date'],
            $day['gross_sales'],
            $day['refunds'],
            $day['expenses'],
            $day['net_sales'],
            $day['transactions'],
        ], $trend);
    }

    /** @return array{title: string, categoryCol: int, valueCol: int} */
    private function trendChartConfig(): array
    {
        return [
            'title' => 'Daily Net Sales',
            'categoryCol' => 0,
            'valueCol' => 4,
        ];
    }

    /** @return array{title: string, headers: list<string>, rows: list<list<mixed>>, chart: array{title: string, categoryCol: int, valueCol: int}}|null */
    private function trendBlock(array $trend): ?array
    {
        if ($trend === []) {
            return null;
        }

        return [
            'title' => 'Daily Performance Trend',
            'headers' => ['Date', 'Gross Sales', 'Refunds', 'Expenses', 'Net Sales', 'Transactions'],
            'rows' => $this->trendExportRows($trend),
            'chart' => $this->trendChartConfig(),
        ];
    }

    private function pdfOrientation(string $reportKey): string
    {
        return in_array($reportKey, [
            'daily-sales',
            'shift-reconciliation',
            'inventory',
            'sales-trend',
            'payment-breakdown',
        ], true) ? 'landscape' : 'portrait';
    }

    private function xlsx(
        Business $business,
        string $reportKey,
        ?string $dateFrom,
        ?string $dateTo,
        string $reportTitle,
        string $accentHex,
        array $summaryCards,
        array $headers,
        array $rows,
        ?string $subtitle = null,
        ?string $purpose = null,
        ?array $insightLines = null,
        ?array $chart = null,
        ?array $trendBlock = null,
    ) {
        return $this->export->downloadRichXlsx([
            'filename' => $this->export->buildFilename($business, $reportKey, $dateFrom, $dateTo),
            'business' => $business,
            'reportTitle' => $reportTitle,
            'reportSubtitle' => $subtitle,
            'reportPurpose' => $purpose,
            'accentHex' => $accentHex,
            'summaryCards' => $summaryCards,
            'insightLines' => $insightLines,
            'headers' => $headers,
            'rows' => $rows,
            'chart' => $chart,
            'trendBlock' => $trendBlock,
        ]);
    }

    public function businessSummary(Request $request)
    {
        [$dateFrom, $dateTo] = $this->getDateRange($request);
        $filters = $this->filters($request);
        $business = $this->getBusiness($request);
        $format = $request->query('format', 'pdf');

        $summary = $this->metrics->periodSummary($business->id, $dateFrom, $dateTo, $filters['user_id'], $filters['shift_id']);
        $trend = $this->metrics->dateRangeTrend($business->id, $dateFrom, $dateTo, $filters['user_id'], $filters['shift_id']);
        $insights = $this->metrics->buildTrendInsights($trend, $summary);

        $headers = ['Metric', 'Amount'];
        $exportRows = [
            ['Gross Sales', $summary['gross_sales']],
            ['Refunds', $summary['refunds']],
            ['Expenses', $summary['expenses']],
            ['Net Sales', $summary['net_sales']],
            ['Transactions', $summary['transactions']],
            ['Refund Rate %', $summary['refund_rate_pct']],
            ['Expense Ratio %', $summary['expense_ratio_pct']],
        ];

        $filename = $this->export->buildFilename($business, 'business-summary', $dateFrom, $dateTo);

        $summaryCards = $this->metrics->plSummaryCards($summary, $business->currency, $this->export);

        return match ($format) {
            'xlsx' => $this->xlsx($business, 'business-summary', $dateFrom, $dateTo, 'Business Summary (P&L)', '#1e40af', $summaryCards, $headers, $exportRows, $this->dateSubtitle($dateFrom, $dateTo), 'Answers: Am I making money this period?', $this->metrics->insightLines($insights, $business->currency, $this->export), null, $this->trendBlock($trend)),
            'csv' => $this->export->downloadCsv($filename, $headers, $exportRows),
            default => $this->export->downloadPdf('reports.business-summary', $this->pdfData($request, [
                'summary' => $summary,
                'trend' => $trend,
                'insights' => $insights,
                'dateFrom' => $dateFrom,
                'dateTo' => $dateTo,
                'accent' => '#1e40af',
                'reportTitle' => 'Business Summary (P&L)',
                'reportPurpose' => 'Answers: Am I making money this period?',
                'reportSubtitle' => $this->dateSubtitle($dateFrom, $dateTo),
                'summaryCards' => $summaryCards,
            ]), $filename, $this->pdfOrientation('business-summary')),
        };
    }

    public function dailySales(Request $request)
    {
        [$dateFrom, $dateTo] = $this->getDateRange($request);
        $filters = $this->filters($request);
        $business = $this->getBusiness($request);
        $format = $request->query('format', 'pdf');

        $query = Sale::where('business_id', $business->id)
            ->whereDate('sale_date', '>=', $dateFrom)
            ->whereDate('sale_date', '<=', $dateTo)
            ->with(['saleItems', 'user', 'customer'])
            ->orderBy('sale_date');
        $this->metrics->applySaleFilters($query, $filters['user_id'], $filters['shift_id']);
        $sales = $query->get();

        $period = $this->metrics->periodSummary($business->id, $dateFrom, $dateTo, $filters['user_id'], $filters['shift_id']);
        $saleTotals = ['gross' => 0.0, 'refunds' => 0.0, 'net_after_refunds' => 0.0];
        $exportRows = [];

        foreach ($sales as $sale) {
            $row = $this->metrics->saleRow($sale);
            $saleTotals['gross'] += $row['gross'];
            $saleTotals['refunds'] += $row['refunds'];
            $saleTotals['net_after_refunds'] += $row['net_after_refunds'];
            $exportRows[] = [
                $sale->receipt_number,
                $sale->sale_date->format('Y-m-d'),
                $sale->user?->name ?? '—',
                $sale->saleItems->count(),
                $this->metrics->paymentMethodLabel($this->metrics->normalizePaymentMethod($sale->payment_method)),
                $this->metrics->paymentStatusLabel($sale->payment_status),
                $row['gross'],
                $row['refunds'],
                $row['net_after_refunds'],
            ];
        }

        $headers = ['Receipt', 'Date', 'Cashier', 'Items', 'Payment', 'Status', 'Gross', 'Refunds', 'Net (after refunds)'];
        $exportRows[] = ['', '', '', '', '', 'Receipt totals', $saleTotals['gross'], $saleTotals['refunds'], $saleTotals['net_after_refunds']];
        $exportRows[] = ['', '', '', '', '', 'Period expenses', '', '', $period['expenses']];
        $exportRows[] = ['', '', '', '', '', ReportExportService::NET_SALES_FORMULA_LABEL, '', '', $period['net_sales']];

        $filename = $this->export->buildFilename($business, 'daily-sales', $dateFrom, $dateTo);
        $summaryCards = $this->metrics->plSummaryCards($period, $business->currency, $this->export);

        return match ($format) {
            'xlsx' => $this->xlsx($business, 'daily-sales', $dateFrom, $dateTo, 'Daily Sales Report', '#1e40af', $summaryCards, $headers, $exportRows, $this->dateSubtitle($dateFrom, $dateTo), 'Answers: What sold, who sold it, and what was refunded?'),
            'csv' => $this->export->downloadCsv($filename, $headers, $exportRows),
            default => $this->export->downloadPdf('reports.daily-sales', $this->pdfData($request, [
                'sales' => $sales,
                'dateFrom' => $dateFrom,
                'dateTo' => $dateTo,
                'period' => $period,
                'saleTotals' => $saleTotals,
                'accent' => '#1e40af',
                'reportTitle' => 'Daily Sales Report',
                'reportPurpose' => 'Answers: What sold, who sold it, and what was refunded?',
                'reportSubtitle' => $this->dateSubtitle($dateFrom, $dateTo),
                'summaryCards' => $summaryCards,
            ]), $filename, $this->pdfOrientation('daily-sales')),
        };
    }

    public function salesTrend(Request $request)
    {
        [$dateFrom, $dateTo] = $this->getDateRange($request);
        $filters = $this->filters($request);
        $business = $this->getBusiness($request);
        $format = $request->query('format', 'pdf');

        $trend = $this->metrics->dateRangeTrend($business->id, $dateFrom, $dateTo, $filters['user_id'], $filters['shift_id']);
        $totals = $this->metrics->trendTotals($trend);
        $insights = $this->metrics->buildTrendInsights($trend, $totals);

        $headers = ['Date', 'Gross Sales', 'Refunds', 'Expenses', 'Net Sales', 'Transactions'];
        $exportRows = array_map(fn ($day) => [
            $day['date'], $day['gross_sales'], $day['refunds'], $day['expenses'], $day['net_sales'], $day['transactions'],
        ], $trend);
        $exportRows[] = ['Total', $totals['gross_sales'], $totals['refunds'], $totals['expenses'], $totals['net_sales'], $totals['transactions']];

        $filename = $this->export->buildFilename($business, 'sales-trend', $dateFrom, $dateTo);
        $summaryCards = $this->metrics->plSummaryCards($totals, $business->currency, $this->export);
        $chart = count($trend) >= 1
            ? array_merge($this->trendChartConfig(), ['excludeLastRows' => 1])
            : null;

        return match ($format) {
            'xlsx' => $this->xlsx($business, 'sales-trend', $dateFrom, $dateTo, 'Sales Trend Report', '#16a34a', $summaryCards, $headers, $exportRows, $this->dateSubtitle($dateFrom, $dateTo), 'Answers: How is daily net performance changing?', $this->metrics->insightLines($insights, $business->currency, $this->export), $chart),
            'csv' => $this->export->downloadCsv($filename, $headers, $exportRows),
            default => $this->export->downloadPdf('reports.sales-trend', $this->pdfData($request, [
                'trend' => $trend,
                'totals' => $totals,
                'insights' => $insights,
                'dateFrom' => $dateFrom,
                'dateTo' => $dateTo,
                'accent' => '#16a34a',
                'reportTitle' => 'Sales Trend Report',
                'reportPurpose' => 'Answers: How is daily net performance changing?',
                'reportSubtitle' => $this->dateSubtitle($dateFrom, $dateTo),
                'summaryCards' => $summaryCards,
            ]), $filename, $this->pdfOrientation('sales-trend')),
        };
    }

    public function expenses(Request $request)
    {
        [$dateFrom, $dateTo] = $this->getDateRange($request);
        $filters = $this->filters($request);
        $business = $this->getBusiness($request);
        $format = $request->query('format', 'pdf');

        $expenseQuery = Expense::where('business_id', $business->id)
            ->whereDate('expense_date', '>=', $dateFrom)
            ->whereDate('expense_date', '<=', $dateTo)
            ->with('expenseCategory')
            ->orderBy('expense_date');
        if ($filters['shift_id']) {
            $expenseQuery->where('shift_id', $filters['shift_id']);
        }
        $expenses = $expenseQuery->get();

        $categorySummary = $this->metrics->expenseCategorySummary($expenses);
        $total = (float) $expenses->sum('amount');
        $period = $this->metrics->periodSummary($business->id, $dateFrom, $dateTo, $filters['user_id'], $filters['shift_id']);

        $headers = ['Date', 'Category', 'Description', 'Amount'];
        $exportRows = $expenses->map(fn ($expense) => [
            $expense->expense_date instanceof \Carbon\Carbon ? $expense->expense_date->format('Y-m-d') : $expense->expense_date,
            $expense->expenseCategory?->name ?? '—',
            $expense->description,
            (float) $expense->amount,
        ])->toArray();
        $exportRows[] = ['', '', 'Total Expenses', $total];
        $exportRows[] = ['', '', 'Period Net Sales (after expenses)', $period['net_sales']];

        $filename = $this->export->buildFilename($business, 'expenses', $dateFrom, $dateTo);
        $summaryCards = $this->metrics->plSummaryCards($period, $business->currency, $this->export);

        return match ($format) {
            'xlsx' => $this->xlsx($business, 'expenses', $dateFrom, $dateTo, 'Expense Report', '#dc2626', $summaryCards, $headers, $exportRows, $this->dateSubtitle($dateFrom, $dateTo), 'Answers: Where is money leaking through operating costs?'),
            'csv' => $this->export->downloadCsv($filename, $headers, $exportRows),
            default => $this->export->downloadPdf('reports.expenses', $this->pdfData($request, [
                'expenses' => $expenses,
                'categorySummary' => $categorySummary,
                'period' => $period,
                'dateFrom' => $dateFrom,
                'dateTo' => $dateTo,
                'total' => $total,
                'accent' => '#dc2626',
                'reportTitle' => 'Expense Report',
                'reportPurpose' => 'Answers: Where is money leaking through operating costs?',
                'reportSubtitle' => $this->dateSubtitle($dateFrom, $dateTo),
                'summaryCards' => $summaryCards,
            ]), $filename, $this->pdfOrientation('expenses')),
        };
    }

    public function inventory(Request $request)
    {
        $business = $this->getBusiness($request);
        $format = $request->query('format', 'xlsx');

        $products = Product::where('business_id', $business->id)->with('category')->orderBy('name')->get();
        $totalValue = $products->sum(fn ($p) => (float) $p->unit_price * (int) $p->stock_quantity);
        $lowStockCount = $products->filter(fn ($p) => $p->stock_quantity <= $p->low_stock_threshold)->count();
        $deadStock = $products->filter(fn ($p) => $p->is_active && $p->stock_quantity > 0)->count();

        $headers = ['Product', 'Category', 'SKU', 'Stock', 'Threshold', 'Unit Price', 'Stock Value', 'Status'];
        $exportRows = $products->map(function ($product) {
            $stockValue = (float) $product->unit_price * (int) $product->stock_quantity;
            $isLow = $product->stock_quantity <= $product->low_stock_threshold;

            return [
                $product->name,
                $product->category?->name ?? '—',
                $product->sku ?? '—',
                $product->stock_quantity,
                $product->low_stock_threshold,
                (float) $product->unit_price,
                $stockValue,
                $product->is_active ? ($isLow ? 'Low stock' : 'Active') : 'Inactive',
            ];
        })->toArray();
        $exportRows[] = ['', '', '', '', '', 'Total inventory value', $totalValue, "{$lowStockCount} low stock"];

        $filename = $this->export->buildFilename($business, 'inventory');

        return match ($format) {
            'csv' => $this->export->downloadCsv($filename, $headers, $exportRows),
            'pdf' => $this->export->downloadPdf('reports.inventory', $this->pdfData($request, [
                'products' => $products,
                'totalValue' => $totalValue,
                'lowStockCount' => $lowStockCount,
                'deadStock' => $deadStock,
                'accent' => '#7c3aed',
                'reportTitle' => 'Inventory Report',
                'reportPurpose' => 'Answers: What must I reorder and what is my stock worth?',
                'reportSubtitle' => 'As of '.now()->format('M d, Y'),
                'summaryCards' => [
                    ['label' => 'Products', 'value' => (string) $products->count()],
                    ['label' => 'Inventory Value', 'value' => $this->export->formatMoney($totalValue, $business->currency)],
                    ['label' => 'Low Stock', 'value' => (string) $lowStockCount, 'tone' => $lowStockCount > 0 ? 'negative' : ''],
                    ['label' => 'Active SKUs', 'value' => (string) $products->where('is_active', true)->count()],
                ],
            ]), $filename, $this->pdfOrientation('inventory')),
            default => $this->xlsx($business, 'inventory', null, null, 'Inventory Report', '#7c3aed', [
                ['label' => 'Products', 'value' => (string) $products->count()],
                ['label' => 'Inventory Value', 'value' => $this->export->formatMoney($totalValue, $business->currency)],
                ['label' => 'Low Stock', 'value' => (string) $lowStockCount, 'tone' => $lowStockCount > 0 ? 'negative' : ''],
                ['label' => 'Active SKUs', 'value' => (string) $products->where('is_active', true)->count()],
            ], $headers, $exportRows, 'As of '.now()->format('M d, Y'), 'Answers: What must I reorder and what is my stock worth?'),
        };
    }

    public function paymentBreakdown(Request $request)
    {
        [$dateFrom, $dateTo] = $this->getDateRange($request);
        $filters = $this->filters($request);
        $business = $this->getBusiness($request);
        $format = $request->query('format', 'csv');

        $breakdown = $this->metrics->paymentBreakdown($business->id, $dateFrom, $dateTo, $filters['user_id'], $filters['shift_id']);
        $totals = [
            'count' => collect($breakdown)->sum('count'),
            'gross' => collect($breakdown)->sum('gross'),
            'refunds' => collect($breakdown)->sum('refunds'),
            'net' => collect($breakdown)->sum('net'),
        ];

        $headers = ['Method', 'Transactions', 'Gross', 'Refunds', 'Net (after refunds)', 'Share %'];
        $exportRows = array_map(fn ($row) => [
            $row['label'], $row['count'], $row['gross'], $row['refunds'], $row['net'], $row['share_pct'],
        ], $breakdown);
        $exportRows[] = ['Total', $totals['count'], $totals['gross'], $totals['refunds'], $totals['net'], 100];

        $filename = $this->export->buildFilename($business, 'payment-breakdown', $dateFrom, $dateTo);

        $summaryCards = [
            ['label' => 'Gross Collected', 'value' => $this->export->formatMoney($totals['gross'], $business->currency)],
            ['label' => 'Refunds', 'value' => '-'.$this->export->formatMoney($totals['refunds'], $business->currency), 'tone' => 'negative'],
            ['label' => 'Net Collected', 'value' => $this->export->formatMoney($totals['net'], $business->currency), 'tone' => 'positive'],
            ['label' => 'Transactions', 'value' => (string) $totals['count']],
        ];

        return match ($format) {
            'xlsx' => $this->xlsx($business, 'payment-breakdown', $dateFrom, $dateTo, 'Payment Method Breakdown', '#ca8a04', $summaryCards, $headers, $exportRows, $this->dateSubtitle($dateFrom, $dateTo), 'Answers: How are customers paying and how much was collected?'),
            'pdf' => $this->export->downloadPdf('reports.payment-breakdown', $this->pdfData($request, [
                'breakdown' => $breakdown,
                'dateFrom' => $dateFrom,
                'dateTo' => $dateTo,
                'totals' => $totals,
                'accent' => '#ca8a04',
                'reportTitle' => 'Payment Method Breakdown',
                'reportPurpose' => 'Answers: How are customers paying and how much was collected?',
                'reportSubtitle' => $this->dateSubtitle($dateFrom, $dateTo),
                'summaryCards' => $summaryCards,
            ]), $filename, $this->pdfOrientation('payment-breakdown')),
            default => $this->export->downloadCsv($filename, $headers, $exportRows),
        };
    }

    public function shiftReconciliation(Request $request)
    {
        [$dateFrom, $dateTo] = $this->getDateRange($request);
        $filters = $this->filters($request);
        $business = $this->getBusiness($request);
        $format = $request->query('format', 'pdf');

        $shifts = $this->metrics->shiftReconciliation($business->id, $dateFrom, $dateTo, $filters['shift_id'], $filters['user_id']);

        $headers = ['Shift', 'Cashier', 'Transactions', 'Gross', 'Refunds', 'Expenses', 'Net Sales', 'Cash Handover'];
        $exportRows = array_map(fn ($row) => [
            $row['shift']->clock_in->format('Y-m-d H:i'),
            $row['cashier'],
            $row['transaction_count'],
            $row['gross_sales'],
            $row['refunds'],
            $row['shift_expenses'],
            $row['net_sales'],
            $row['cash_handover'],
        ], $shifts);

        $filename = $this->export->buildFilename($business, 'shift-reconciliation', $dateFrom, $dateTo);

        $summaryCards = [
            ['label' => 'Shifts', 'value' => (string) count($shifts)],
            ['label' => 'Total Handover', 'value' => $this->export->formatMoney(collect($shifts)->sum('cash_handover'), $business->currency), 'tone' => 'positive'],
            ['label' => 'Shift Expenses', 'value' => $this->export->formatMoney(collect($shifts)->sum('shift_expenses'), $business->currency), 'tone' => 'negative'],
            ['label' => 'Transactions', 'value' => (string) collect($shifts)->sum('transaction_count')],
        ];

        return match ($format) {
            'xlsx' => $this->xlsx($business, 'shift-reconciliation', $dateFrom, $dateTo, 'Shift Reconciliation', '#0f766e', $summaryCards, $headers, $exportRows, $this->dateSubtitle($dateFrom, $dateTo), 'Answers: How much cash should be at handover per shift?'),
            'csv' => $this->export->downloadCsv($filename, $headers, $exportRows),
            default => $this->export->downloadPdf('reports.shift-reconciliation', $this->pdfData($request, [
                'shifts' => $shifts,
                'dateFrom' => $dateFrom,
                'dateTo' => $dateTo,
                'accent' => '#0f766e',
                'reportTitle' => 'Shift Reconciliation',
                'reportPurpose' => 'Answers: How much cash should be at handover per shift?',
                'reportSubtitle' => $this->dateSubtitle($dateFrom, $dateTo),
                'summaryCards' => $summaryCards,
            ]), $filename, $this->pdfOrientation('shift-reconciliation')),
        };
    }

    public function shiftClose(Request $request)
    {
        $request->validate(['shift_id' => 'required|integer']);
        $business = $this->getBusiness($request);
        $shiftId = (int) $request->query('shift_id');
        $user = $request->user();

        $shift = Shift::where('business_id', $business->id)->where('id', $shiftId)->first();
        if (! $shift) {
            return response()->json(['message' => 'Shift not found'], 404);
        }

        if (! $this->canAccessShiftCloseReport($user, $shift)) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $report = $this->metrics->shiftCloseReport($business->id, $shiftId);
        $currency = $business->currency;

        $summaryCards = [
            ['label' => 'Cash at handover', 'value' => $this->export->formatMoney($report['cash_handover'], $currency), 'tone' => 'positive'],
            ['label' => 'Net sales', 'value' => $this->export->formatMoney($report['net_sales'], $currency), 'tone' => 'positive'],
            ['label' => 'Transactions', 'value' => (string) $report['transaction_count']],
            ['label' => 'Shift expenses', 'value' => '-'.$this->export->formatMoney($report['shift_expenses'], $currency), 'tone' => 'negative'],
        ];

        $shift = $report['shift'];
        $subtitle = $shift->clock_out
            ? collect([
                'Closed '.$shift->clock_out->format('M d, Y H:i'),
                $report['duration'] ? 'Duration '.$report['duration'] : null,
            ])->filter()->implode(' · ')
            : 'Started '.$shift->clock_in->format('M d, Y H:i').' · Report as of '.now()->format('M d, Y H:i');

        $filename = $this->export->buildShiftCloseFilename(
            $business,
            $report['cashier'],
            $report['shift']->clock_out,
        );

        return $this->export->downloadPdf('reports.shift-close', $this->pdfData($request, [
            'report' => $report,
            'accent' => '#1e40af',
            'reportTitle' => 'Shift Close Report',
            'reportPurpose' => null,
            'reportSubtitle' => $subtitle,
            'summaryCards' => $summaryCards,
        ]), $filename, 'portrait');
    }

    private function canAccessShiftCloseReport($user, Shift $shift): bool
    {
        if ((int) $shift->business_id !== (int) $user->business_id) {
            return false;
        }

        if ($user->hasPermission('reports.view')) {
            return true;
        }

        if ($user->hasPermission('shifts.close_report')) {
            return (int) $shift->user_id === (int) $user->id;
        }

        return false;
    }

    public function productPerformance(Request $request)
    {
        [$dateFrom, $dateTo] = $this->getDateRange($request);
        $filters = $this->filters($request);
        $business = $this->getBusiness($request);
        $format = $request->query('format', 'pdf');

        $report = $this->metrics->productPerformanceReport($business->id, $dateFrom, $dateTo, $filters['user_id']);
        $products = $report['products'];

        $headers = ['Product', 'Qty Sold', 'Gross', 'Refunds', 'Net (after refunds)'];
        $exportRows = array_map(fn ($p) => [
            $p['product_name'], $p['quantity_sold'], $p['gross_revenue'], $p['refunds'], $p['net_after_refunds'],
        ], $products);

        $filename = $this->export->buildFilename($business, 'product-performance', $dateFrom, $dateTo);

        $topByNet = $report['top_by_net'][0] ?? null;
        $topByQty = $report['top_by_quantity'][0] ?? null;

        $summaryCards = [
            ['label' => 'Products Sold', 'value' => (string) count($products)],
            ['label' => 'Top by Net', 'value' => $topByNet['product_name'] ?? 'N/A'],
            ['label' => 'Top by Qty', 'value' => $topByQty ? $topByQty['product_name'].' ('.$topByQty['quantity_sold'].' units)' : 'N/A', 'tone' => 'positive'],
            ['label' => 'No Sales', 'value' => (string) $report['no_sales_count'], 'tone' => $report['no_sales_count'] > 0 ? 'negative' : ''],
        ];
        $insightLines = $this->metrics->productPerformanceInsightLines($report, $business->currency, $this->export);

        return match ($format) {
            'xlsx' => $this->xlsx($business, 'product-performance', $dateFrom, $dateTo, 'Product Performance', '#4f46e5', $summaryCards, $headers, $exportRows, $this->dateSubtitle($dateFrom, $dateTo), 'Answers: What products drive revenue and which are underperforming?', $insightLines),
            'csv' => $this->export->downloadCsv($filename, $headers, $exportRows),
            default => $this->export->downloadPdf('reports.product-performance', $this->pdfData($request, [
                'products' => $products,
                'top_by_net' => $report['top_by_net'],
                'top_by_quantity' => $report['top_by_quantity'],
                'slowest_sold' => $report['slowest_sold'],
                'no_sales' => $report['no_sales'],
                'no_sales_count' => $report['no_sales_count'],
                'dateFrom' => $dateFrom,
                'dateTo' => $dateTo,
                'accent' => '#4f46e5',
                'reportTitle' => 'Product Performance',
                'reportPurpose' => 'Answers: What products drive revenue, volume, and which need attention?',
                'reportSubtitle' => $this->dateSubtitle($dateFrom, $dateTo),
                'summaryCards' => $summaryCards,
            ]), $filename, $this->pdfOrientation('product-performance')),
        };
    }
}
