<?php

namespace App\Services\Concerns;

use App\Models\Product;
use App\Services\ReportExportService;
use Illuminate\Support\Facades\DB;

trait ComputesProductPerformance
{
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
}
