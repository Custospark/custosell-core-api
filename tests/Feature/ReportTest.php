<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\Product;
use App\Models\Role;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\User;
use Database\Seeders\PlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReportTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected User $staff;

    protected Business $business;

    protected string $adminToken;

    protected string $staffToken;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(PlanSeeder::class);

        $this->admin = User::factory()->create(['is_active' => true]);
        $this->adminToken = $this->admin->createToken('admin')->plainTextToken;

        $this->business = Business::factory()->create([
            'owner_id' => $this->admin->id,
            'name' => 'Acme Shop',
            'currency' => 'UGX',
            'status' => 'active',
            'slug' => 'acme-shop',
        ]);
        $this->admin->business_id = $this->business->id;
        $this->admin->save();

        $adminRole = Role::create([
            'business_id' => $this->business->id,
            'name' => 'Admin',
            'slug' => 'admin',
            'permissions' => [
                'sales.create' => true, 'sales.view' => true, 'sales.refund' => true,
                'inventory.view' => true, 'inventory.create' => true,
                'customers.view' => true, 'customers.create' => true,
                'expenses.view' => true, 'expenses.create' => true,
                'users.view' => true, 'users.create' => true,
                'reports.view' => true, 'settings.view' => true, 'settings.edit' => true,
            ],
        ]);
        $this->admin->role_id = $adminRole->id;
        $this->admin->save();

        $this->staff = User::factory()->create([
            'business_id' => $this->business->id,
            'is_active' => true,
        ]);
        $staffRole = Role::create([
            'business_id' => $this->business->id,
            'name' => 'Staff',
            'slug' => 'staff',
            'permissions' => [
                'sales.create' => true, 'sales.view' => true, 'sales.refund' => false,
                'inventory.view' => true, 'inventory.create' => false,
                'customers.view' => true, 'customers.create' => true,
                'expenses.view' => false, 'expenses.create' => false,
                'users.view' => false, 'users.create' => false,
                'reports.view' => false, 'settings.view' => false, 'settings.edit' => false,
            ],
        ]);
        $this->staff->role_id = $staffRole->id;
        $this->staff->save();
        $this->staffToken = $this->staff->createToken('staff')->plainTextToken;
    }

    public function test_staff_without_reports_permission_is_forbidden(): void
    {
        $response = $this->withHeader('Authorization', "Bearer $this->staffToken")
            ->get('/api/v1/reports/daily-sales?format=csv');

        $response->assertStatus(403);
    }

    public function test_daily_sales_csv_uses_net_accounting_columns(): void
    {
        $sale = Sale::create([
            'business_id' => $this->business->id,
            'user_id' => $this->admin->id,
            'receipt_number' => 'RPT-001',
            'subtotal' => 10000,
            'total_amount' => 10000,
            'payment_method' => 'cash',
            'payment_status' => 'partially_refunded',
            'sale_date' => now(),
        ]);
        SaleItem::create([
            'sale_id' => $sale->id,
            'product_name' => 'Soap',
            'product_price' => 10000,
            'quantity' => 1,
            'unit_price' => 10000,
            'subtotal' => 10000,
            'refunded_quantity' => 1,
            'refunded_amount' => 2000,
        ]);

        $response = $this->withHeader('Authorization', "Bearer $this->adminToken")
            ->get('/api/v1/reports/daily-sales?format=csv');

        $response->assertStatus(200)
            ->assertHeader('content-type', 'text/csv; charset=UTF-8');

        $body = $response->getContent();
        $this->assertStringContainsString('Gross', $body);
        $this->assertStringContainsString('Refunds', $body);
        $this->assertStringContainsString('Net', $body);
        $this->assertStringContainsString('8000', $body);
    }

    public function test_sales_trend_csv_includes_refunds_expenses_and_net_revenue(): void
    {
        $sale = Sale::create([
            'business_id' => $this->business->id,
            'user_id' => $this->admin->id,
            'receipt_number' => 'RPT-TRD-001',
            'subtotal' => 50000,
            'total_amount' => 50000,
            'payment_method' => 'cash',
            'sale_date' => now(),
        ]);
        SaleItem::create([
            'sale_id' => $sale->id,
            'product_name' => 'Rice',
            'product_price' => 50000,
            'quantity' => 1,
            'unit_price' => 50000,
            'subtotal' => 50000,
            'refunded_amount' => 5000,
            'refunded_quantity' => 0,
        ]);
        Expense::create([
            'business_id' => $this->business->id,
            'recorded_by' => $this->admin->id,
            'amount' => 3000,
            'description' => 'Fuel',
            'expense_date' => now(),
        ]);

        $response = $this->withHeader('Authorization', "Bearer $this->adminToken")
            ->get('/api/v1/reports/sales-trend?format=csv&date_from='.now()->toDateString().'&date_to='.now()->toDateString());

        $response->assertStatus(200);
        $body = $response->getContent();
        $this->assertStringContainsString('Gross Sales', $body);
        $this->assertStringContainsString('Net Sales', $body);
        $this->assertStringContainsString('42000', $body);
    }

    public function test_payment_breakdown_groups_card_and_other_and_uses_net_totals(): void
    {
        Sale::create([
            'business_id' => $this->business->id,
            'user_id' => $this->admin->id,
            'receipt_number' => 'CARD-001',
            'subtotal' => 10000,
            'total_amount' => 10000,
            'payment_method' => 'card',
            'sale_date' => now(),
        ]);
        Sale::create([
            'business_id' => $this->business->id,
            'user_id' => $this->admin->id,
            'receipt_number' => 'OTH-001',
            'subtotal' => 5000,
            'total_amount' => 5000,
            'payment_method' => 'other',
            'sale_date' => now(),
        ]);

        $response = $this->withHeader('Authorization', "Bearer $this->adminToken")
            ->get('/api/v1/reports/payment-breakdown?format=csv');

        $response->assertStatus(200);
        $body = $response->getContent();
        $this->assertStringContainsString('Card / Other', $body);
        $this->assertStringContainsString('15000', $body);
    }

    public function test_inventory_csv_includes_stock_value(): void
    {
        Product::factory()->create([
            'business_id' => $this->business->id,
            'name' => 'Sugar',
            'unit_price' => 2000,
            'stock_quantity' => 10,
            'low_stock_threshold' => 2,
            'is_active' => true,
        ]);

        $response = $this->withHeader('Authorization', "Bearer $this->adminToken")
            ->get('/api/v1/reports/inventory?format=csv');

        $response->assertStatus(200);
        $body = $response->getContent();
        $this->assertStringContainsString('Stock Value', $body);
        $this->assertStringContainsString('20000', $body);
    }

    public function test_business_summary_csv_returns_net_sales(): void
    {
        Sale::create([
            'business_id' => $this->business->id,
            'user_id' => $this->admin->id,
            'receipt_number' => 'PL-001',
            'subtotal' => 20000,
            'total_amount' => 20000,
            'payment_method' => 'cash',
            'sale_date' => now(),
        ]);
        Expense::create([
            'business_id' => $this->business->id,
            'recorded_by' => $this->admin->id,
            'amount' => 5000,
            'description' => 'Rent',
            'expense_date' => now(),
        ]);

        $response = $this->withHeader('Authorization', "Bearer $this->adminToken")
            ->get('/api/v1/reports/business-summary?format=csv');

        $response->assertStatus(200);
        $body = $response->getContent();
        $this->assertStringContainsString('Net Sales', $body);
        $this->assertStringContainsString('15000', $body);
    }

    public function test_invalid_date_range_returns_422(): void
    {
        $response = $this->withHeader('Authorization', "Bearer $this->adminToken")
            ->getJson('/api/v1/reports/daily-sales?format=csv&date_from=2026-06-10&date_to=2026-06-01');

        $response->assertStatus(422)
            ->assertJsonPath('message', 'date_from must be on or before date_to');
    }

    public function test_report_filename_uses_business_name_and_date_range(): void
    {
        $response = $this->withHeader('Authorization', "Bearer $this->adminToken")
            ->get('/api/v1/reports/daily-sales?format=csv&date_from=2026-06-01&date_to=2026-06-08');

        $response->assertStatus(200);
        $disposition = $response->headers->get('content-disposition');
        $this->assertStringContainsString('acme-shop-daily-sales-2026-06-01_to_2026-06-08.csv', $disposition);
    }

    public function test_product_performance_csv_includes_rankings_and_no_sales(): void
    {
        $soldProduct = Product::factory()->create([
            'business_id' => $this->business->id,
            'name' => 'Best Seller',
            'is_active' => true,
        ]);
        Product::factory()->create([
            'business_id' => $this->business->id,
            'name' => 'Shelf Warmer',
            'is_active' => true,
        ]);

        $sale = Sale::create([
            'business_id' => $this->business->id,
            'user_id' => $this->admin->id,
            'receipt_number' => 'PP-001',
            'subtotal' => 10000,
            'total_amount' => 10000,
            'payment_method' => 'cash',
            'sale_date' => now(),
        ]);
        SaleItem::create([
            'sale_id' => $sale->id,
            'product_id' => $soldProduct->id,
            'product_name' => 'Best Seller',
            'product_price' => 5000,
            'quantity' => 2,
            'unit_price' => 5000,
            'subtotal' => 10000,
        ]);

        $response = $this->withHeader('Authorization', "Bearer $this->adminToken")
            ->get('/api/v1/reports/product-performance?format=csv');

        $response->assertStatus(200);
        $body = $response->getContent();
        $this->assertStringContainsString('Best Seller', $body);
        $this->assertStringContainsString('2', $body);
    }

    public function test_daily_sales_xlsx_is_rich_workbook(): void
    {
        Sale::create([
            'business_id' => $this->business->id,
            'user_id' => $this->admin->id,
            'receipt_number' => 'XLS-001',
            'subtotal' => 15000,
            'total_amount' => 15000,
            'payment_method' => 'cash',
            'sale_date' => now(),
        ]);

        $response = $this->withHeader('Authorization', "Bearer $this->adminToken")
            ->get('/api/v1/reports/daily-sales?format=xlsx');

        $response->assertStatus(200)
            ->assertHeader('content-type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');

        $disposition = $response->headers->get('content-disposition');
        $this->assertStringContainsString('acme-shop-daily-sales', $disposition);
    }
}
