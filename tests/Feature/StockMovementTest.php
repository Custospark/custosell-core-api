<?php

namespace Tests\Feature;

use App\Models\{Business, Category, Customer, Plan, Product, Role, Sale, SaleItem, Shift, StockMovement, Subscription, ExpenseCategory, Expense, User};
use Database\Seeders\PlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StockMovementTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;
    protected User $staff;
    protected Business $business;
    protected string $adminToken;
    protected string $staffToken;
    protected Product $product;

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

        $this->product = Product::factory()->create([
            'business_id' => $this->business->id,
            'stock_quantity' => 10,
        ]);
    }

    public function test_list_stock_movements_for_product(): void
    {
        StockMovement::create([
            'business_id' => $this->business->id,
            'product_id' => $this->product->id,
            'type' => 'purchase',
            'quantity_change' => 10,
            'stock_before' => 0,
            'stock_after' => 10,
            'created_by' => $this->admin->id,
        ]);

        $response = $this->withHeader('Authorization', "Bearer $this->adminToken")
            ->getJson('/api/v1/stock-movements');

        $response->assertStatus(200)
            ->assertJsonStructure(['data']);
    }

    public function test_create_manual_adjustment(): void
    {
        $response = $this->withHeader('Authorization', "Bearer $this->adminToken")
            ->postJson('/api/v1/stock-movements', [
                'product_id' => $this->product->id,
                'type' => 'adjustment',
                'quantity_change' => -2,
                'stock_before' => $this->product->stock_quantity,
                'stock_after' => $this->product->stock_quantity - 2,
                'notes' => 'Damaged goods adjustment',
            ]);

        $response->assertStatus(201)
            ->assertJsonStructure(['id', 'type', 'quantity_change', 'stock_before', 'stock_after'])
            ->assertJsonPath('type', 'adjustment');
    }

    public function test_purchase_stock_movement(): void
    {
        $response = $this->withHeader('Authorization', "Bearer $this->adminToken")
            ->postJson('/api/v1/stock-movements', [
                'product_id' => $this->product->id,
                'type' => 'purchase',
                'quantity_change' => 50,
                'stock_before' => $this->product->stock_quantity,
                'stock_after' => $this->product->stock_quantity + 50,
                'reference' => 'PO-001',
                'notes' => 'Supplier restock',
            ]);

        $response->assertStatus(201)
            ->assertJsonPath('type', 'purchase')
            ->assertJsonPath('quantity_change', 50);
    }

    public function test_stock_before_after_correct(): void
    {
        $stockBefore = $this->product->stock_quantity;

        $response = $this->withHeader('Authorization', "Bearer $this->adminToken")
            ->postJson('/api/v1/stock-movements', [
                'product_id' => $this->product->id,
                'type' => 'adjustment',
                'quantity_change' => 5,
                'stock_before' => $stockBefore,
                'stock_after' => $stockBefore + 5,
                'notes' => 'Inventory count correction',
            ]);

        $response->assertStatus(201);
        $response->assertJsonPath('stock_before', $stockBefore);
        $response->assertJsonPath('stock_after', $stockBefore + 5);
    }

    public function test_movement_creates_record(): void
    {
        $response = $this->withHeader('Authorization', "Bearer $this->adminToken")
            ->postJson('/api/v1/stock-movements', [
                'product_id' => $this->product->id,
                'type' => 'purchase',
                'quantity_change' => 20,
                'stock_before' => $this->product->stock_quantity,
                'stock_after' => $this->product->stock_quantity + 20,
                'reference' => 'PO-002',
            ]);

        $response->assertStatus(201)
            ->assertJsonPath('stock_after', $this->product->stock_quantity + 20);
    }
}
