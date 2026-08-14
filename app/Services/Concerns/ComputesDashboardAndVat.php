<?php

namespace App\Services\Concerns;

use App\Models\Business;
use App\Models\Customer;
use App\Models\Expense;
use App\Models\Product;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Support\TaxEngine;

trait ComputesDashboardAndVat
{
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
                'category' => $expense->expenseCategory?->name ?? '-',
                'description' => $expense->description,
                'supplier_tin' => $expense->supplier_tin,
                'supplier_invoice_no' => $expense->supplier_invoice_no,
                'amount' => (float) $expense->amount,
                'vat_amount' => (float) ($expense->vat_amount ?? 0),
            ])
            ->all();
    }
}
