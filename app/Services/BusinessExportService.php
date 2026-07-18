<?php

namespace App\Services;

use App\Models\Business;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class BusinessExportService
{
    public function __construct(
        protected ReportExportService $export,
    ) {}

    public function exportJson(Business $business): array
    {
        $data = $this->gatherAll($business);

        return [
            'exported_at' => now()->toIso8601String(),
            'business' => $data['business'],
            'products' => $data['products'],
            'categories' => $data['categories'],
            'customers' => $data['customers'],
            'sales' => $data['sales'],
            'sale_items' => $data['sale_items'],
            'expenses' => $data['expenses'],
            'expense_categories' => $data['expense_categories'],
            'invoices' => $data['invoices'],
            'invoice_items' => $data['invoice_items'],
            'payments' => $data['payments'],
            'orders' => $data['orders'],
            'purchase_orders' => $data['purchase_orders'],
            'purchase_order_items' => $data['purchase_order_items'],
            'stock_movements' => $data['stock_movements'],
            'pipeline_boards' => $data['pipeline_boards'],
            'leads' => $data['leads'],
            'estimates' => $data['estimates'],
            'projects' => $data['projects'],
            'documents' => $data['documents'],
            'chart_of_accounts' => $data['chart_of_accounts'],
            'journal_entries' => $data['journal_entries'],
            'general_ledger' => $data['general_ledger'],
            'users' => $data['users'],
            'roles' => $data['roles'],
            'shifts' => $data['shifts'],
            'notifications' => $data['notifications'],
        ];
    }

    public function exportCsv(Business $business, string $entity): array
    {
        $data = $this->gatherEntity($business, $entity);

        return [$data['headers'], $data['rows']];
    }

    public function exportXlsx(Business $business, string $entity): array
    {
        return $this->exportCsv($business, $entity);
    }

    public function supportedEntities(): array
    {
        return [
            'products', 'categories', 'customers', 'sales', 'sale_items',
            'expenses', 'expense_categories', 'invoices', 'invoice_items',
            'payments', 'orders', 'purchase_orders', 'purchase_order_items',
            'stock_movements', 'pipeline_boards', 'leads', 'estimates',
            'projects', 'documents', 'chart_of_accounts', 'journal_entries',
            'general_ledger', 'users', 'roles', 'shifts',
        ];
    }

    private function gatherAll(Business $business): array
    {
        $id = $business->id;

        return [
            'business' => $business->toArray(),
            'products' => DB::table('products')->where('business_id', $id)->get()->toArray(),
            'categories' => DB::table('categories')->where('business_id', $id)->get()->toArray(),
            'customers' => DB::table('customers')->where('business_id', $id)->get()->toArray(),
            'sales' => DB::table('sales')->where('business_id', $id)->get()->toArray(),
            'sale_items' => DB::table('sale_items')
                ->whereIn('sale_id', fn($q) => $q->select('id')->from('sales')->where('business_id', $id))
                ->get()->toArray(),
            'expenses' => DB::table('expenses')->where('business_id', $id)->get()->toArray(),
            'expense_categories' => DB::table('expense_categories')->where('business_id', $id)->get()->toArray(),
            'invoices' => DB::table('invoices')->where('business_id', $id)->get()->toArray(),
            'invoice_items' => DB::table('invoice_items')
                ->whereIn('invoice_id', fn($q) => $q->select('id')->from('invoices')->where('business_id', $id))
                ->get()->toArray(),
            'payments' => DB::table('payments')->where('business_id', $id)->get()->toArray(),
            'orders' => DB::table('orders')->where('business_id', $id)->get()->toArray(),
            'purchase_orders' => DB::table('purchase_orders')
                ->where('buyer_business_id', $id)->orWhere('seller_business_id', $id)
                ->get()->toArray(),
            'purchase_order_items' => DB::table('purchase_order_items')
                ->whereIn('purchase_order_id', fn($q) => $q->select('id')->from('purchase_orders')
                    ->where('buyer_business_id', $id)->orWhere('seller_business_id', $id))
                ->get()->toArray(),
            'stock_movements' => DB::table('stock_movements')->where('business_id', $id)->get()->toArray(),
            'pipeline_boards' => DB::table('pipeline_boards')->where('business_id', $id)->get()->toArray(),
            'leads' => DB::table('pipeline_leads')->where('business_id', $id)->get()->toArray(),
            'estimates' => DB::table('estimates')->where('business_id', $id)->get()->toArray(),
            'projects' => DB::table('projects')->where('business_id', $id)->get()->toArray(),
            'documents' => DB::table('documents')->where('business_id', $id)->get()->toArray(),
            'chart_of_accounts' => DB::table('chart_of_accounts')->where('business_id', $id)->get()->toArray(),
            'journal_entries' => DB::table('journal_entries')->where('business_id', $id)->get()->toArray(),
            'general_ledger' => DB::table('general_ledger')->where('business_id', $id)->get()->toArray(),
            'users' => User::where('business_id', $id)->get(['id', 'name', 'email', 'phone', 'role_id', 'is_active', 'last_login_at'])->toArray(),
            'roles' => DB::table('roles')->where('business_id', $id)->get()->toArray(),
            'shifts' => DB::table('shifts')->where('business_id', $id)->get()->toArray(),
            'notifications' => DB::table('notifications')->where('business_id', $id)->get()->toArray(),
        ];
    }

    private function gatherEntity(Business $business, string $entity): array
    {
        return match ($entity) {
            'products' => [
                'headers' => ['ID', 'Name', 'SKU', 'Barcode', 'Unit', 'Unit Price', 'Wholesale Price', 'Cost Price', 'Stock Qty', 'Low Stock Threshold', 'Tax %', 'Category ID', 'Description'],
                'rows' => DB::table('products')->where('business_id', $business->id)
                    ->select(['id', 'name', 'sku', 'barcode', 'unit', 'unit_price', 'wholesale_price', 'cost_price', 'stock_quantity', 'low_stock_threshold', 'tax_rate', 'category_id', 'description'])->get()->toArray(),
            ],
            'customers' => [
                'headers' => ['ID', 'Name', 'Email', 'Phone', 'Address', 'Total Purchases', 'Last Purchase'],
                'rows' => DB::table('customers')->where('business_id', $business->id)
                    ->select(['id', 'name', 'email', 'phone', 'address', 'total_purchases', 'last_purchase_at'])->get()->toArray(),
            ],
            'sales' => [
                'headers' => ['ID', 'Date', 'Total', 'Discount', 'Tax', 'Status', 'Customer ID', 'User ID'],
                'rows' => DB::table('sales')->where('business_id', $business->id)
                    ->select(['id', 'sale_date', 'total_amount', 'discount_amount', 'tax_amount', 'status', 'customer_id', 'user_id'])->get()->toArray(),
            ],
            default => ['headers' => [], 'rows' => []],
        };
    }
}
