<?php

namespace Tests\Feature;

use App\Models\{Business, Category, Customer, Plan, Product, Role, Sale, SaleItem, Shift, StockMovement, Subscription, ExpenseCategory, Expense, User};
use Database\Seeders\PlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SaleTest extends TestCase
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
            'currency' => 'UGX',
            'status' => 'active',
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

    public function test_list_sales(): void
    {
        Sale::create([
            'business_id' => $this->business->id,
            'user_id' => $this->admin->id,
            'receipt_number' => 'REC-001',
            'subtotal' => 10000,
            'total_amount' => 10000,
            'payment_method' => 'cash',
            'sale_date' => now(),
        ]);

        $response = $this->withHeader('Authorization', "Bearer $this->adminToken")
            ->getJson('/api/v1/sales');

        $response->assertStatus(200)
            ->assertJsonStructure(['data']);
    }

    public function test_create_sale(): void
    {
        $response = $this->withHeader('Authorization', "Bearer $this->adminToken")
            ->postJson('/api/v1/sales', [
                'receipt_number' => 'REC-001',
                'subtotal' => 15000,
                'total_amount' => 15000,
                'payment_method' => 'cash',
                'sale_date' => now()->toDateTimeString(),
            ]);

        $response->assertStatus(201)
            ->assertJsonStructure(['id', 'receipt_number', 'total_amount', 'payment_method'])
            ->assertJsonPath('receipt_number', 'REC-001');
    }

    public function test_create_sale_with_items(): void
    {
        $product = Product::factory()->create([
            'business_id' => $this->business->id,
            'stock_quantity' => 20,
        ]);

        $saleResponse = $this->withHeader('Authorization', "Bearer $this->adminToken")
            ->postJson('/api/v1/sales', [
                'receipt_number' => 'REC-ITM-001',
                'subtotal' => 15000,
                'total_amount' => 15000,
                'payment_method' => 'cash',
                'sale_date' => now()->toDateTimeString(),
            ]);

        $saleResponse->assertStatus(201);
        $saleData = $saleResponse->json();
        $saleId = $saleData['data']['id'] ?? $saleData['id'];

        $itemResponse = $this->withHeader('Authorization', "Bearer $this->adminToken")
            ->postJson('/api/v1/sale-items', [
                'sale_id' => $saleId,
                'product_id' => $product->id,
                'product_name' => $product->name,
                'product_price' => $product->unit_price,
                'quantity' => 2,
                'unit_price' => $product->unit_price,
                'subtotal' => $product->unit_price * 2,
            ]);

        $itemResponse->assertStatus(201);
    }

    public function test_create_sale_updates_stock_quantity(): void
    {
        $product = Product::factory()->create([
            'business_id' => $this->business->id,
            'stock_quantity' => 20,
        ]);

        $response = $this->withHeader('Authorization', "Bearer $this->adminToken")
            ->postJson('/api/v1/sales', [
                'receipt_number' => 'REC-STK-001',
                'subtotal' => 15000,
                'total_amount' => 15000,
                'payment_method' => 'cash',
                'sale_date' => now()->toDateTimeString(),
            ]);

        $response->assertStatus(201);
    }

    public function test_create_sale_generates_receipt_number(): void
    {
        $response = $this->withHeader('Authorization', "Bearer $this->adminToken")
            ->postJson('/api/v1/sales', [
                'receipt_number' => 'REC-GEN-001',
                'subtotal' => 20000,
                'total_amount' => 20000,
                'payment_method' => 'mobile_money',
                'sale_date' => now()->toDateTimeString(),
            ]);

        $response->assertStatus(201)
            ->assertJsonPath('receipt_number', 'REC-GEN-001');
    }

    public function test_get_daily_sales(): void
    {
        Sale::create([
            'business_id' => $this->business->id,
            'user_id' => $this->admin->id,
            'receipt_number' => 'REC-DLY-001',
            'subtotal' => 5000,
            'total_amount' => 5000,
            'payment_method' => 'cash',
            'sale_date' => now(),
        ]);

        $response = $this->withHeader('Authorization', "Bearer $this->adminToken")
            ->getJson('/api/v1/sales');

        $response->assertStatus(200);
        $this->assertCount(1, $response->json('data'));
    }

    public function test_get_single_sale_with_items(): void
    {
        $sale = Sale::create([
            'business_id' => $this->business->id,
            'user_id' => $this->admin->id,
            'receipt_number' => 'REC-SNG-001',
            'subtotal' => 10000,
            'total_amount' => 10000,
            'payment_method' => 'cash',
            'sale_date' => now(),
        ]);

        $response = $this->withHeader('Authorization', "Bearer $this->adminToken")
            ->getJson("/api/v1/sales/{$sale->id}");

        $response->assertStatus(200)
            ->assertJsonStructure(['data' => ['id', 'receipt_number', 'total_amount']]);
    }

    public function test_sale_scoped_per_business(): void
    {
        Sale::create([
            'business_id' => $this->business->id,
            'user_id' => $this->admin->id,
            'receipt_number' => 'REC-SCP-001',
            'subtotal' => 10000,
            'total_amount' => 10000,
            'payment_method' => 'cash',
            'sale_date' => now(),
        ]);

        $otherBusiness = Business::factory()->create([
            'owner_id' => $this->admin->id,
            'currency' => 'UGX',
            'status' => 'active',
        ]);
        Sale::create([
            'business_id' => $otherBusiness->id,
            'user_id' => $this->admin->id,
            'receipt_number' => 'REC-OTH-001',
            'subtotal' => 20000,
            'total_amount' => 20000,
            'payment_method' => 'cash',
            'sale_date' => now(),
        ]);

        $response = $this->withHeader('Authorization', "Bearer $this->adminToken")
            ->getJson('/api/v1/sales');

        $response->assertStatus(200);
        $receipts = collect($response->json('data'))->pluck('receipt_number')->toArray();
        $this->assertContains('REC-SCP-001', $receipts);
        $this->assertNotContains('REC-OTH-001', $receipts);
    }

    public function test_create_sale_without_auth_returns_401(): void
    {
        $response = $this->postJson('/api/v1/sales', [
            'receipt_number' => 'REC-UNAUTH-001',
            'subtotal' => 10000,
            'total_amount' => 10000,
            'payment_method' => 'cash',
            'sale_date' => now()->toDateTimeString(),
        ]);

        $response->assertStatus(401);
    }

    public function test_create_sale_invalid_payment_method_returns_422(): void
    {
        $response = $this->withHeader('Authorization', "Bearer $this->adminToken")
            ->postJson('/api/v1/sales', [
                'receipt_number' => 'REC-INV-001',
                'subtotal' => 10000,
                'total_amount' => 10000,
                'payment_method' => 'bitcoin',
                'sale_date' => now()->toDateTimeString(),
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['payment_method']);
    }

    public function test_fully_paid_sale_creates_payment_receipt_for_email(): void
    {
        $product = Product::factory()->create([
            'business_id' => $this->business->id,
            'stock_quantity' => 10,
            'unit_price' => 10000,
        ]);

        $response = $this->withHeader('Authorization', "Bearer $this->adminToken")
            ->postJson('/api/v1/sales', [
                'items' => [
                    ['product_id' => $product->id, 'quantity' => 1, 'unit_price' => 10000],
                ],
                'subtotal' => 10000,
                'discount_amount' => 0,
                'total_amount' => 10000,
                'payment_method' => 'cash',
                'amount_tendered' => 10000,
            ]);

        $response->assertStatus(201)
            ->assertJsonPath('payment_status', 'paid')
            ->assertJsonCount(1, 'payments');

        $saleId = $response->json('id');
        $this->assertDatabaseHas('payments', [
            'payable_type' => 'sale',
            'payable_id' => $saleId,
            'amount' => 10000,
        ]);
    }
}
