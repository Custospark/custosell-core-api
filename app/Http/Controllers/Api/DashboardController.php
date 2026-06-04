<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\Customer;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\Expense;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    public function summary(Request $request): JsonResponse
    {
        $businessId = $request->user()->business_id;
        $today = now()->format('Y-m-d');

        // Today's sales
        $todaySales = Sale::where('business_id', $businessId)
            ->whereDate('sale_date', $today)
            ->get();

        $todayRevenue = (float) $todaySales->sum('total_amount');
        $todayTransactions = $todaySales->count();
        $todayProductsSold = (int) SaleItem::whereIn('sale_id', $todaySales->pluck('id'))
            ->sum('quantity');

        // Counts
        $activeProducts = Product::where('business_id', $businessId)
            ->where('is_active', true)
            ->count();

        $totalCustomers = Customer::where('business_id', $businessId)->count();

        // 7-day sales trend
        $salesTrend = [];
        for ($i = 6; $i >= 0; $i--) {
            $date = now()->subDays($i)->format('Y-m-d');
            $daySales = Sale::where('business_id', $businessId)
                ->whereDate('sale_date', $date)
                ->get();
            $salesTrend[] = [
                'date' => $date,
                'revenue' => (float) $daySales->sum('total_amount'),
                'transactions' => $daySales->count(),
            ];
        }

        // Low stock products
        $lowStock = Product::where('business_id', $businessId)
            ->where('is_active', true)
            ->whereColumn('stock_quantity', '<=', 'low_stock_threshold')
            ->get(['id', 'name', 'stock_quantity', 'low_stock_threshold']);

        // Recent sales (last 5)
        $recentSales = Sale::where('business_id', $businessId)
            ->with('saleItems')
            ->orderBy('created_at', 'desc')
            ->limit(5)
            ->get()
            ->map(fn($s) => [
                'id' => $s->id,
                'receipt_number' => $s->receipt_number,
                'total_amount' => (float) $s->total_amount,
                'payment_method' => $s->payment_method,
                'created_at' => $s->created_at,
                'items_count' => $s->saleItems->count(),
            ]);

        // Today's expenses
        $todayExpenses = (float) Expense::where('business_id', $businessId)
            ->whereDate('expense_date', $today)
            ->sum('amount');

        return response()->json([
            'today_revenue' => $todayRevenue,
            'today_transactions' => $todayTransactions,
            'today_products_sold' => $todayProductsSold,
            'today_expenses' => $todayExpenses,
            'active_products' => $activeProducts,
            'total_customers' => $totalCustomers,
            'sales_trend' => $salesTrend,
            'low_stock' => $lowStock,
            'recent_sales' => $recentSales,
        ]);
    }
}
