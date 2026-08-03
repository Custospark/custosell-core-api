<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\Location;
use App\Models\Role;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\User;
use Database\Seeders\PlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BranchPerformanceTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected Business $business;

    protected string $adminToken;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(PlanSeeder::class);

        $this->admin = User::factory()->create(['is_active' => true]);
        $this->adminToken = $this->admin->createToken('admin')->plainTextToken;

        $this->business = Business::factory()->create([
            'owner_id' => $this->admin->id,
            'name' => 'Branch Shop',
            'currency' => 'UGX',
            'status' => 'active',
            'slug' => 'branch-shop',
        ]);
        $this->admin->business_id = $this->business->id;
        $this->admin->save();

        $this->ensureSubscription($this->business->id);

        $role = Role::create([
            'business_id' => $this->business->id,
            'name' => 'Admin',
            'slug' => 'admin',
            'permissions' => [
                'sales.create' => true, 'sales.view' => true, 'sales.refund' => true,
                'expenses.view' => true, 'expenses.create' => true,
                'reports.view' => true, 'settings.view' => true, 'settings.edit' => true,
            ],
        ]);
        $this->admin->role_id = $role->id;
        $this->admin->save();
    }

    public function test_branch_performance_subtracts_expenses_from_net_sales(): void
    {
        $main = Location::create([
            'business_id' => $this->business->id,
            'name' => 'Main Branch',
            'code' => 'MAIN',
            'is_default' => true,
            'is_active' => true,
        ]);
        $annex = Location::create([
            'business_id' => $this->business->id,
            'name' => 'Annex Branch',
            'code' => 'ANNEX',
            'is_default' => false,
            'is_active' => true,
        ]);

        $sale = Sale::create([
            'business_id' => $this->business->id,
            'user_id' => $this->admin->id,
            'location_id' => $main->id,
            'receipt_number' => 'BR-001',
            'subtotal' => 100000,
            'total_amount' => 100000,
            'payment_method' => 'cash',
            'payment_status' => 'paid',
            'sale_date' => now(),
        ]);
        SaleItem::create([
            'sale_id' => $sale->id,
            'product_name' => 'Item',
            'product_price' => 100000,
            'quantity' => 1,
            'unit_price' => 100000,
            'subtotal' => 100000,
            'refunded_quantity' => 0,
            'refunded_amount' => 0,
        ]);

        $category = ExpenseCategory::create([
            'business_id' => $this->business->id,
            'name' => 'Rent',
            'slug' => 'rent',
        ]);
        Expense::create([
            'business_id' => $this->business->id,
            'expense_category_id' => $category->id,
            'recorded_by' => $this->admin->id,
            'location_id' => $main->id,
            'amount' => 40000,
            'description' => 'Rent',
            'expense_date' => now(),
        ]);
        Expense::create([
            'business_id' => $this->business->id,
            'expense_category_id' => $category->id,
            'recorded_by' => $this->admin->id,
            'location_id' => $annex->id,
            'amount' => 15000,
            'description' => 'Annex utilities',
            'expense_date' => now(),
        ]);

        $response = $this->withHeader('Authorization', "Bearer $this->adminToken")
            ->getJson('/api/v1/reports/branch-performance');

        $response->assertOk();

        $branches = collect($response->json('data.branches'))->keyBy('location_id');

        $mainRow = $branches->get($main->id);
        $this->assertNotNull($mainRow, 'Main branch should be present.');
        $this->assertEqualsWithDelta(100000, $mainRow['gross_sales'], 0.01);
        $this->assertEqualsWithDelta(40000, $mainRow['expenses'], 0.01);
        $this->assertEqualsWithDelta(60000, $mainRow['net_sales'], 0.01);

        $annexRow = $branches->get($annex->id);
        $this->assertNotNull($annexRow, 'Expense-only branch should be included.');
        $this->assertEqualsWithDelta(0, $annexRow['gross_sales'], 0.01);
        $this->assertEqualsWithDelta(15000, $annexRow['expenses'], 0.01);
        $this->assertEqualsWithDelta(0, $annexRow['net_sales'], 0.01);
    }

    public function test_branch_performance_includes_refunds_and_floors_net_sales_at_zero(): void
    {
        $main = Location::create([
            'business_id' => $this->business->id,
            'name' => 'Main Branch',
            'code' => 'MAIN',
            'is_default' => true,
            'is_active' => true,
        ]);

        $sale = Sale::create([
            'business_id' => $this->business->id,
            'user_id' => $this->admin->id,
            'location_id' => $main->id,
            'receipt_number' => 'BR-002',
            'subtotal' => 30000,
            'total_amount' => 30000,
            'payment_method' => 'cash',
            'payment_status' => 'partially_refunded',
            'sale_date' => now(),
        ]);
        SaleItem::create([
            'sale_id' => $sale->id,
            'product_name' => 'Item',
            'product_price' => 30000,
            'quantity' => 1,
            'unit_price' => 30000,
            'subtotal' => 30000,
            'refunded_quantity' => 1,
            'refunded_amount' => 30000,
        ]);

        $category = ExpenseCategory::create([
            'business_id' => $this->business->id,
            'name' => 'Rent',
            'slug' => 'rent-2',
        ]);
        Expense::create([
            'business_id' => $this->business->id,
            'expense_category_id' => $category->id,
            'recorded_by' => $this->admin->id,
            'location_id' => $main->id,
            'amount' => 50000,
            'description' => 'Rent',
            'expense_date' => now(),
        ]);

        $response = $this->withHeader('Authorization', "Bearer $this->adminToken")
            ->getJson('/api/v1/reports/branch-performance');

        $response->assertOk();

        $branches = collect($response->json('data.branches'))->keyBy('location_id');
        $mainRow = $branches->get($main->id);

        $this->assertEqualsWithDelta(30000, $mainRow['refunds'], 0.01);
        $this->assertEqualsWithDelta(50000, $mainRow['expenses'], 0.01);
        $this->assertEqualsWithDelta(0, $mainRow['net_sales'], 0.01);
    }
}
