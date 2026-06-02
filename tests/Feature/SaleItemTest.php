<?php

namespace Tests\Feature;

use App\Models\{Business, Category, Customer, Plan, Product, Role, Sale, SaleItem, Shift, StockMovement, Subscription, ExpenseCategory, Expense, User};
use Database\Seeders\PlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SaleItemTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;
    protected User $staff;
    protected Business $business;
    protected string $adminToken;
    protected string $staffToken;
    protected Sale $sale;
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
            'stock_quantity' => 50,
        ]);

        $this->sale = Sale::create([
            'business_id' => $this->business->id,
            'user_id' => $this->admin->id,
            'receipt_number' => 'REC-SI-001',
            'subtotal' => 30000,
            'total_amount' => 30000,
            'payment_method' => 'cash',
            'sale_date' => now(),
        ]);
    }

    public function test_list_items_for_a_sale(): void
    {
        SaleItem::create([
            'sale_id' => $this->sale->id,
            'product_id' => $this->product->id,
            'product_name' => $this->product->name,
            'product_price' => $this->product->unit_price,
            'quantity' => 2,
            'unit_price' => $this->product->unit_price,
            'subtotal' => $this->product->unit_price * 2,
        ]);

        $response = $this->withHeader('Authorization', "Bearer $this->adminToken")
            ->getJson('/api/v1/sale-items');

        $response->assertStatus(200)
            ->assertJsonStructure(['data']);
    }

    public function test_create_sale_item(): void
    {
        $response = $this->withHeader('Authorization', "Bearer $this->adminToken")
            ->postJson('/api/v1/sale-items', [
                'sale_id' => $this->sale->id,
                'product_id' => $this->product->id,
                'product_name' => $this->product->name,
                'product_price' => $this->product->unit_price,
                'quantity' => 1,
                'unit_price' => $this->product->unit_price,
                'subtotal' => $this->product->unit_price,
            ]);

        $response->assertStatus(201)
            ->assertJsonStructure(['id', 'sale_id', 'product_name', 'quantity'])
            ->assertJsonPath('product_name', $this->product->name);
    }

    public function test_update_sale_item(): void
    {
        $saleItem = SaleItem::create([
            'sale_id' => $this->sale->id,
            'product_id' => $this->product->id,
            'product_name' => $this->product->name,
            'product_price' => $this->product->unit_price,
            'quantity' => 1,
            'unit_price' => $this->product->unit_price,
            'subtotal' => $this->product->unit_price,
        ]);

        $response = $this->withHeader('Authorization', "Bearer $this->adminToken")
            ->putJson("/api/v1/sale-items/{$saleItem->id}", [
                'sale_id' => $this->sale->id,
                'product_name' => $this->product->name,
                'product_price' => $this->product->unit_price,
                'quantity' => 3,
                'unit_price' => $this->product->unit_price,
                'subtotal' => $this->product->unit_price * 3,
            ]);

        $response->assertStatus(200)
            ->assertJsonPath('data.quantity', 3)
            ->assertJsonPath('data.product_name', $this->product->name);
    }

    public function test_delete_sale_item(): void
    {
        $saleItem = SaleItem::create([
            'sale_id' => $this->sale->id,
            'product_id' => $this->product->id,
            'product_name' => $this->product->name,
            'product_price' => $this->product->unit_price,
            'quantity' => 1,
            'unit_price' => $this->product->unit_price,
            'subtotal' => $this->product->unit_price,
        ]);

        $response = $this->withHeader('Authorization', "Bearer $this->adminToken")
            ->deleteJson("/api/v1/sale-items/{$saleItem->id}");

        $response->assertStatus(204);
        $this->assertDatabaseMissing('sale_items', ['id' => $saleItem->id]);
    }

    public function test_product_name_snapshot_saved(): void
    {
        $productName = $this->product->name;

        $response = $this->withHeader('Authorization', "Bearer $this->adminToken")
            ->postJson('/api/v1/sale-items', [
                'sale_id' => $this->sale->id,
                'product_id' => $this->product->id,
                'product_name' => $productName,
                'product_price' => $this->product->unit_price,
                'quantity' => 1,
                'unit_price' => $this->product->unit_price,
                'subtotal' => $this->product->unit_price,
            ]);

        $response->assertStatus(201)
            ->assertJsonPath('product_name', $productName);

        $this->product->update(['name' => 'Renamed Product']);

        $itemData = $response->json();
        $itemId = $itemData['data']['id'] ?? $itemData['id'];
        $saleItem = SaleItem::find($itemId);
        $this->assertEquals($productName, $saleItem->product_name);
    }
}
