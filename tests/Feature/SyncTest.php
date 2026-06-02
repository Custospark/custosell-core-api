<?php

namespace Tests\Feature;

use App\Models\{Business, Category, Customer, Plan, Product, Role, Sale, SaleItem, Shift, StockMovement, Subscription, ExpenseCategory, Expense, User};
use Database\Seeders\PlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SyncTest extends TestCase
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

    public function test_push_categories(): void
    {
        $response = $this->withHeader('Authorization', "Bearer $this->adminToken")
            ->postJson('/api/v1/sync/push', [
                'categories' => [
                    ['name' => 'Offline Category 1'],
                    ['name' => 'Offline Category 2'],
                ],
            ]);

        $response->assertStatus(200);

        $this->assertDatabaseHas('categories', [
            'business_id' => $this->business->id,
            'name' => 'Offline Category 1',
        ]);
        $this->assertDatabaseHas('categories', [
            'business_id' => $this->business->id,
            'name' => 'Offline Category 2',
        ]);
    }

    public function test_push_products(): void
    {
        $response = $this->withHeader('Authorization', "Bearer $this->adminToken")
            ->postJson('/api/v1/sync/push', [
                'products' => [
                    [
                        'name' => 'Offline Product',
                        'unit_price' => 10000,
                        'stock_quantity' => 5,
                    ],
                ],
            ]);

        $response->assertStatus(200);

        $this->assertDatabaseHas('products', [
            'business_id' => $this->business->id,
            'name' => 'Offline Product',
        ]);
    }

    public function test_push_without_auth_returns_401(): void
    {
        $response = $this->postJson('/api/v1/sync/push', [
            'categories' => [],
        ]);

        $response->assertStatus(401);
    }

    public function test_pull_all_data(): void
    {
        Category::factory()->create([
            'business_id' => $this->business->id,
            'name' => 'Sync Category',
        ]);

        $response = $this->withHeader('Authorization', "Bearer $this->adminToken")
            ->getJson('/api/v1/sync/pull');

        $response->assertStatus(200);
    }

    public function test_pull_with_since_filter(): void
    {
        Category::factory()->create([
            'business_id' => $this->business->id,
            'name' => 'Old Category',
        ]);

        $response = $this->withHeader('Authorization', "Bearer $this->adminToken")
            ->getJson('/api/v1/sync/pull?since=' . now()->subDay()->toIso8601String());

        $response->assertStatus(200);
    }

    public function test_full_sync_initial_dump(): void
    {
        Category::factory()->create([
            'business_id' => $this->business->id,
            'name' => 'Full Sync Category',
        ]);
        Product::factory()->create([
            'business_id' => $this->business->id,
            'name' => 'Full Sync Product',
        ]);

        $response = $this->withHeader('Authorization', "Bearer $this->adminToken")
            ->getJson('/api/v1/sync/full');

        $response->assertStatus(200);
    }

    public function test_full_sync_without_auth_returns_401(): void
    {
        $response = $this->getJson('/api/v1/sync/full');

        $response->assertStatus(401);
    }

    public function test_pull_scoped_to_business(): void
    {
        Category::factory()->create([
            'business_id' => $this->business->id,
            'name' => 'Our Sync Category',
        ]);

        $otherBusiness = Business::factory()->create([
            'owner_id' => $this->admin->id,
            'currency' => 'UGX',
            'status' => 'active',
        ]);
        Category::factory()->create([
            'business_id' => $otherBusiness->id,
            'name' => 'Other Sync Category',
        ]);

        $response = $this->withHeader('Authorization', "Bearer $this->adminToken")
            ->getJson('/api/v1/sync/pull');

        $response->assertStatus(200);
        $body = $response->json();
        $categories = collect($body['categories'] ?? []);
        $categoryNames = $categories->pluck('name')->toArray();
        $this->assertContains('Our Sync Category', $categoryNames);
        $this->assertNotContains('Other Sync Category', $categoryNames);
    }
}
