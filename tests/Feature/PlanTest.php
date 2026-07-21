<?php

namespace Tests\Feature;

use App\Models\{Business, Category, Customer, Plan, Product, Role, Sale, SaleItem, Shift, StockMovement, Subscription, ExpenseCategory, Expense, User};
use Database\Seeders\PlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PlanTest extends TestCase
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

    public function test_list_all_plans_returns_seeded_data(): void
    {
        $response = $this->getJson('/api/v1/plans');

        $response->assertStatus(200)
            ->assertJsonStructure(['data']);
    }

    public function test_plans_contain_free_pro_premium(): void
    {
        $response = $this->getJson('/api/v1/plans');

        $response->assertStatus(200);
        $names = collect($response->json('data'))->pluck('name')->toArray();
        $this->assertContains('Essential', $names);
        $this->assertContains('Professional', $names);
        $this->assertContains('Enterprise', $names);
    }

    public function test_get_single_plan(): void
    {
        $plan = Plan::where('slug', 'essential')->first();

        $response = $this->getJson("/api/v1/plans/{$plan->id}");

        $response->assertStatus(200)
            ->assertJsonStructure(['data' => ['id', 'name', 'slug', 'price_monthly', 'features', 'limits']])
            ->assertJsonPath('data.name', 'Essential');
    }

    public function test_get_non_existent_plan_returns_404(): void
    {
        $response = $this->getJson('/api/v1/plans/999');

        $response->assertStatus(404);
    }

    public function test_create_plan(): void
    {
        $response = $this->postJson('/api/v1/plans', [
            'name' => 'Enterprise Plus',
            'slug' => 'enterprise-plus',
            'description' => 'For large businesses',
            'price_monthly' => 500000,
            'price_yearly' => 5000000,
            'features' => ['expenses' => true, 'shift_tracking' => true, 'discounts' => true, 'refunds' => true, 'export_data' => true],
            'limits' => ['staff_users' => 50, 'products' => 10000, 'monthly_sales' => null, 'customers' => 10000, 'categories' => 100],
            'is_active' => true,
            'sort_order' => 4,
        ]);

        $response->assertStatus(201)
            ->assertJsonStructure(['id', 'name', 'slug', 'price_monthly'])
            ->assertJsonPath('name', 'Enterprise Plus');
    }

    public function test_create_plan_missing_name_returns_422(): void
    {
        $response = $this->postJson('/api/v1/plans', [
            'slug' => 'no-name',
            'price_monthly' => 0,
            'features' => [],
            'limits' => [],
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['name']);
    }

    public function test_create_plan_duplicate_slug_returns_422(): void
    {
        $this->postJson('/api/v1/plans', [
            'name' => 'First Plan',
            'slug' => 'duplicate-slug',
            'price_monthly' => 1000,
            'features' => ['sales' => true],
            'limits' => ['max_products' => 100],
        ]);

        $response = $this->postJson('/api/v1/plans', [
            'name' => 'Second Plan',
            'slug' => 'duplicate-slug',
            'price_monthly' => 2000,
            'features' => ['sales' => true],
            'limits' => ['max_products' => 100],
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['slug']);
    }

    public function test_update_plan(): void
    {
        $plan = Plan::where('slug', 'essential')->first();

        $response = $this->putJson("/api/v1/plans/{$plan->id}", [
            'name' => 'Essential Updated',
            'slug' => 'essential',
            'price_monthly' => 0,
            'features' => ['expenses' => false, 'shift_tracking' => false, 'discounts' => true, 'refunds' => false, 'export_data' => false],
            'limits' => ['staff_users' => 2, 'products' => 100, 'monthly_sales' => 200, 'customers' => 100, 'categories' => 10],
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('data.name', 'Essential Updated');
    }

    public function test_update_plan_price(): void
    {
        $plan = Plan::where('slug', 'professional')->first();

        $response = $this->putJson("/api/v1/plans/{$plan->id}", [
            'name' => 'Professional',
            'slug' => 'professional',
            'price_monthly' => 50000,
            'price_yearly' => 500000,
            'features' => ['expenses' => true, 'shift_tracking' => true, 'discounts' => true, 'refunds' => true, 'export_data' => false],
            'limits' => ['staff_users' => 5, 'products' => 1000, 'monthly_sales' => null, 'customers' => 1000, 'categories' => 30],
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('data.price_monthly', '50000.00');
    }

    public function test_delete_plan(): void
    {
        $plan = Plan::where('slug', 'enterprise')->first();

        $response = $this->deleteJson("/api/v1/plans/{$plan->id}");

        $response->assertStatus(204);
        $this->assertDatabaseMissing('plans', ['id' => $plan->id]);
    }

    public function test_delete_non_existent_plan_returns_500(): void
    {
        $response = $this->deleteJson('/api/v1/plans/999');

        $response->assertStatus(500);
    }

    public function test_plan_features_is_array_not_string(): void
    {
        $plan = Plan::where('slug', 'essential')->first();

        $this->assertIsArray($plan->features);
        $this->assertIsArray($plan->limits);
    }
}
