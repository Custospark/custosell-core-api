<?php

namespace Tests\Feature;

use App\Models\{Business, Category, Customer, Plan, Product, Role, Sale, SaleItem, Shift, StockMovement, Subscription, ExpenseCategory, Expense, User};
use Database\Seeders\PlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProductTest extends TestCase
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

    public function test_list_products(): void
    {
        Product::factory()->count(3)->create([
            'business_id' => $this->business->id,
        ]);

        $response = $this->withHeader('Authorization', "Bearer $this->adminToken")
            ->getJson('/api/v1/products');

        $response->assertStatus(200)
            ->assertJsonStructure(['data']);
    }

    public function test_create_product(): void
    {
        $response = $this->withHeader('Authorization', "Bearer $this->adminToken")
            ->postJson('/api/v1/products', [
                'name' => 'Test Product',
                'unit_price' => 15000,
                'stock_quantity' => 10,
                'sku' => 'SKU-TEST-001',
            ]);

        $response->assertStatus(201)
            ->assertJsonStructure(['id', 'name', 'unit_price', 'stock_quantity'])
            ->assertJsonPath('name', 'Test Product');
    }

    public function test_create_product_with_category(): void
    {
        $category = Category::factory()->create([
            'business_id' => $this->business->id,
        ]);

        $response = $this->withHeader('Authorization', "Bearer $this->adminToken")
            ->postJson('/api/v1/products', [
                'name' => 'Categorized Product',
                'unit_price' => 20000,
                'category_id' => $category->id,
                'sku' => 'SKU-CAT-001',
            ]);

        $response->assertStatus(201)
            ->assertJsonPath('category_id', $category->id);
    }

    public function test_create_product_negative_price_returns_422(): void
    {
        $response = $this->withHeader('Authorization', "Bearer $this->adminToken")
            ->postJson('/api/v1/products', [
                'name' => 'Negative Price',
                'unit_price' => -100,
                'sku' => 'SKU-NEG-001',
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['unit_price']);
    }

    public function test_update_product(): void
    {
        $product = Product::factory()->create([
            'business_id' => $this->business->id,
        ]);

        $response = $this->withHeader('Authorization', "Bearer $this->adminToken")
            ->putJson("/api/v1/products/{$product->id}", [
                'name' => 'Updated Product',
                'unit_price' => 25000,
            ]);

        $response->assertStatus(200)
            ->assertJsonPath('data.name', 'Updated Product');
    }

    public function test_update_stock_quantity(): void
    {
        $product = Product::factory()->create([
            'business_id' => $this->business->id,
            'stock_quantity' => 10,
        ]);

        $response = $this->withHeader('Authorization', "Bearer $this->adminToken")
            ->putJson("/api/v1/products/{$product->id}", [
                'name' => $product->name,
                'unit_price' => $product->unit_price,
                'stock_quantity' => 25,
            ]);

        $response->assertStatus(200)
            ->assertJsonPath('data.stock_quantity', 25);
    }

    public function test_delete_product(): void
    {
        $product = Product::factory()->create([
            'business_id' => $this->business->id,
        ]);

        $response = $this->withHeader('Authorization', "Bearer $this->adminToken")
            ->deleteJson("/api/v1/products/{$product->id}");

        $response->assertStatus(204);
        $this->assertSoftDeleted('products', ['id' => $product->id]);
    }

    public function test_low_stock_products_endpoint(): void
    {
        Product::factory()->create([
            'business_id' => $this->business->id,
            'name' => 'Low Stock Item',
            'stock_quantity' => 2,
            'low_stock_threshold' => 5,
        ]);
        Product::factory()->create([
            'business_id' => $this->business->id,
            'name' => 'Sufficient Stock',
            'stock_quantity' => 50,
            'low_stock_threshold' => 5,
        ]);

        $response = $this->withHeader('Authorization', "Bearer $this->adminToken")
            ->getJson('/api/v1/products/low-stock');

        $response->assertStatus(200);
        $names = collect($response->json('data'))->pluck('name')->toArray();
        $this->assertContains('Low Stock Item', $names);
        $this->assertNotContains('Sufficient Stock', $names);
    }

    public function test_view_stock_movements_for_product(): void
    {
        $product = Product::factory()->create([
            'business_id' => $this->business->id,
            'stock_quantity' => 20,
        ]);

        StockMovement::create([
            'business_id' => $this->business->id,
            'product_id' => $product->id,
            'type' => 'purchase',
            'quantity_change' => 20,
            'stock_before' => 0,
            'stock_after' => 20,
            'created_by' => $this->admin->id,
        ]);

        $response = $this->withHeader('Authorization', "Bearer $this->adminToken")
            ->getJson('/api/v1/stock-movements');

        $response->assertStatus(200);
        $productIds = collect($response->json('data'))->pluck('product_id')->toArray();
        $this->assertContains($product->id, $productIds);
    }

    public function test_products_scoped_per_business(): void
    {
        Product::factory()->create([
            'business_id' => $this->business->id,
            'name' => 'Our Product',
        ]);

        $otherBusiness = Business::factory()->create([
            'owner_id' => $this->admin->id,
            'currency' => 'UGX',
            'status' => 'active',
        ]);
        Product::factory()->create([
            'business_id' => $otherBusiness->id,
            'name' => 'Other Product',
        ]);

        $response = $this->withHeader('Authorization', "Bearer $this->adminToken")
            ->getJson('/api/v1/products');

        $response->assertStatus(200);
        $names = collect($response->json('data'))->pluck('name')->toArray();
        $this->assertContains('Our Product', $names);
        $this->assertNotContains('Other Product', $names);
    }

    public function test_sales_only_staff_can_list_active_products_for_pos(): void
    {
        $cashier = User::factory()->create([
            'business_id' => $this->business->id,
            'is_active' => true,
            'modules' => ['sales'],
            'role_id' => $this->staff->role_id,
        ]);
        $cashierToken = $cashier->createToken('cashier')->plainTextToken;

        Product::factory()->create([
            'business_id' => $this->business->id,
            'name' => 'POS Item',
            'is_active' => true,
        ]);
        Product::factory()->create([
            'business_id' => $this->business->id,
            'name' => 'Inactive Item',
            'is_active' => false,
        ]);

        $this->withHeader('Authorization', "Bearer $cashierToken")
            ->getJson('/api/v1/products')
            ->assertStatus(403);

        $response = $this->withHeader('Authorization', "Bearer $cashierToken")
            ->getJson('/api/v1/products/active');

        $response->assertStatus(200);
        $names = collect($response->json('data'))->pluck('name')->toArray();
        $this->assertContains('POS Item', $names);
        $this->assertNotContains('Inactive Item', $names);
    }
}
