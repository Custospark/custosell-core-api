<?php

namespace App\Services;

use App\Models\Business;
use App\Models\Category;
use App\Models\Customer;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\Invoice;
use App\Models\Product;
use App\Models\Role;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\Shift;
use App\Models\StockMovement;
use App\Models\User;
use App\Services\Contracts\SyncServiceInterface;
use Illuminate\Support\Facades\DB;

class SyncService implements SyncServiceInterface
{
    public function pull(int $businessId, ?string $since): array
    {
        $query = fn ($model) => $model->where('business_id', $businessId)
            ->when($since, fn ($q) => $q->where('updated_at', '>', $since));

        return [
            'categories' => $query(new Category)->get(),
            'products' => $query(new Product)->get(),
            'customers' => $query(new Customer)->get(),
            'expense_categories' => $query(new ExpenseCategory)->get(),
            'expenses' => $query(new Expense)->get(),
            'invoices' => $query(new Invoice)->with('items')->get(),
            'roles' => $query(new Role)->get(),
            'shifts' => $query(new Shift)->get(),
            'sales' => $query(new Sale)->get(),
            'sale_items' => SaleItem::whereIn('sale_id', Sale::where('business_id', $businessId)->pluck('id'))
                ->when($since, fn ($q) => $q->where('updated_at', '>', $since))
                ->get(),
            'stock_movements' => $query(new StockMovement)->get(),
            'users' => User::where('business_id', $businessId)
                ->when($since, fn ($q) => $q->where('updated_at', '>', $since))
                ->get(),
            'synced_at' => now()->toDateTimeString(),
        ];
    }

    public function push(int $businessId, array $payload): array
    {
        $imported = ['categories' => 0, 'products' => 0, 'customers' => 0, 'expenses' => 0, 'invoices' => 0, 'sales' => 0, 'sale_items' => 0, 'stock_movements' => 0];

        DB::transaction(function () use ($businessId, $payload, &$imported) {
            foreach (['categories', 'products', 'customers', 'expenses'] as $type) {
                if (!isset($payload[$type])) {
                    continue;
                }
                foreach ($payload[$type] as $item) {
                    $item['business_id'] = $businessId;
                    $modelClass = match ($type) {
                        'categories' => Category::class,
                        'products' => Product::class,
                        'customers' => Customer::class,
                        'expenses' => Expense::class,
                    };
                    $modelClass::updateOrCreate(
                        ['id' => $item['id'] ?? null, 'business_id' => $businessId],
                        $item,
                    );
                    $imported[$type]++;
                }
            }

            if (isset($payload['sales'])) {
                foreach ($payload['sales'] as $sale) {
                    $sale['business_id'] = $businessId;
                    Sale::updateOrCreate(
                        ['receipt_number' => $sale['receipt_number'], 'business_id' => $businessId],
                        $sale,
                    );
                    $imported['sales']++;
                }
            }

            if (isset($payload['sale_items'])) {
                foreach ($payload['sale_items'] as $item) {
                    SaleItem::updateOrCreate(
                        ['id' => $item['id'] ?? null],
                        $item,
                    );
                    $imported['sale_items']++;
                }
            }

            if (isset($payload['invoices'])) {
                foreach ($payload['invoices'] as $invoice) {
                    $invoice['business_id'] = $businessId;
                    Invoice::updateOrCreate(
                        ['id' => $invoice['id'] ?? null, 'business_id' => $businessId],
                        $invoice,
                    );
                    $imported['invoices']++;
                }
            }

            if (isset($payload['stock_movements'])) {
                foreach ($payload['stock_movements'] as $movement) {
                    $movement['business_id'] = $businessId;
                    if (empty($movement['created_by']) && auth()->id()) {
                        $movement['created_by'] = auth()->id();
                    }
                    StockMovement::create($movement);
                    $imported['stock_movements']++;
                }
            }
        });

        return [
            'imported' => $imported,
            'synced_at' => now()->toDateTimeString(),
        ];
    }

    public function full(int $businessId): array
    {
        $business = Business::with([
            'categories', 'products', 'customers', 'expenseCategories',
            'expenses', 'roles', 'shifts', 'users',
        ])->findOrFail($businessId);

        $sales = Sale::with('items')->where('business_id', $businessId)->get();
        $stockMovements = StockMovement::where('business_id', $businessId)->get();
        $invoices = Invoice::with('items')->where('business_id', $businessId)->get();

        return [
            'business' => $business,
            'categories' => $business->categories,
            'products' => $business->products,
            'customers' => $business->customers,
            'expense_categories' => $business->expenseCategories,
            'expenses' => $business->expenses,
            'invoices' => $invoices,
            'roles' => $business->roles,
            'users' => $business->users,
            'shifts' => $business->shifts,
            'sales' => $sales,
            'stock_movements' => $stockMovements,
            'synced_at' => now()->toDateTimeString(),
        ];
    }
}
