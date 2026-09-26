<?php

namespace App\Services\Assistant;

use App\Models\Invoice;
use App\Models\Product;
use App\Models\Sale;
use Illuminate\Support\Carbon;

/**
 * Read-only business snapshot injected into the assistant prompt so it can
 * answer about live stock, sales and invoices. Bounded row counts keep the
 * prompt small; no writes happen here.
 */
class AssistantContextService
{
    public function snapshot(int $businessId): array
    {
        $today = Carbon::today();

        $lowStock = Product::query()
            ->where('business_id', $businessId)
            ->where('is_active', true)
            ->whereColumn('stock_quantity', '<=', 'low_stock_threshold')
            ->orderBy('stock_quantity')
            ->limit(10)
            ->get(['name', 'stock_quantity', 'low_stock_threshold', 'unit_price'])
            ->map(fn ($p) => [
                'name' => $p->name,
                'stock' => (float) $p->stock_quantity,
                'threshold' => (int) $p->low_stock_threshold,
                'price' => (float) $p->unit_price,
            ])
            ->all();

        $todaySales = Sale::query()
            ->where('business_id', $businessId)
            ->whereDate('sale_date', $today)
            ->selectRaw('COUNT(*) as count, COALESCE(SUM(total_amount), 0) as total')
            ->first();

        $outstanding = Invoice::query()
            ->where('business_id', $businessId)
            ->whereNotIn('status', ['paid', 'cancelled'])
            ->selectRaw('COUNT(*) as count, COALESCE(SUM(total_amount - amount_paid), 0) as total')
            ->first();

        $productCount = Product::query()
            ->where('business_id', $businessId)
            ->where('is_active', true)
            ->count();

        return [
            'date' => $today->toDateString(),
            'currency_note' => 'Amounts are in the business base currency.',
            'active_products' => $productCount,
            'today_sales' => [
                'count' => (int) ($todaySales->count ?? 0),
                'total' => round((float) ($todaySales->total ?? 0), 2),
            ],
            'outstanding_invoices' => [
                'count' => (int) ($outstanding->count ?? 0),
                'total_due' => round((float) ($outstanding->total ?? 0), 2),
            ],
            'low_stock' => $lowStock,
        ];
    }
}
