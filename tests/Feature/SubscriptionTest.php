<?php

namespace Tests\Feature;

use App\Models\{Business, Category, Customer, Plan, Product, Role, Sale, SaleItem, Shift, StockMovement, Subscription, ExpenseCategory, Expense, User};
use Database\Seeders\PlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SubscriptionTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;
    protected User $staff;
    protected Business $business;
    protected string $adminToken;
    protected string $staffToken;
    protected Plan $plan;

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

        $this->plan = Plan::where('slug', 'free')->first();
    }

    public function test_get_current_subscription(): void
    {
        Subscription::create([
            'business_id' => $this->business->id,
            'plan_id' => $this->plan->id,
            'status' => 'active',
            'starts_at' => now(),
        ]);

        $response = $this->withHeader('Authorization', "Bearer $this->adminToken")
            ->getJson('/api/v1/subscriptions');

        $response->assertStatus(200)
            ->assertJsonStructure(['data']);
    }

    public function test_create_subscription(): void
    {
        $response = $this->withHeader('Authorization', "Bearer $this->adminToken")
            ->postJson('/api/v1/subscriptions', [
                'business_id' => $this->business->id,
                'plan_id' => $this->plan->id,
                'status' => 'active',
                'starts_at' => now()->toDateTimeString(),
            ]);

        $response->assertStatus(201)
            ->assertJsonStructure(['id', 'business_id', 'plan_id', 'status'])
            ->assertJsonPath('status', 'active');
    }

    public function test_cannot_create_duplicate_subscription(): void
    {
        Subscription::create([
            'business_id' => $this->business->id,
            'plan_id' => $this->plan->id,
            'status' => 'active',
            'starts_at' => now(),
        ]);

        $response = $this->withHeader('Authorization', "Bearer $this->adminToken")
            ->postJson('/api/v1/subscriptions', [
                'business_id' => $this->business->id,
                'plan_id' => $this->plan->id,
                'status' => 'active',
                'starts_at' => now()->toDateTimeString(),
            ]);

        $response->assertStatus(500);
    }

    public function test_cancel_subscription(): void
    {
        $subscription = Subscription::create([
            'business_id' => $this->business->id,
            'plan_id' => $this->plan->id,
            'status' => 'active',
            'starts_at' => now(),
        ]);

        $response = $this->withHeader('Authorization', "Bearer $this->adminToken")
            ->putJson("/api/v1/subscriptions/{$subscription->id}", [
                'business_id' => $this->business->id,
                'plan_id' => $this->plan->id,
                'status' => 'cancelled',
                'starts_at' => $subscription->starts_at->toDateTimeString(),
                'cancelled_at' => now()->toDateTimeString(),
            ]);

        $response->assertStatus(200)
            ->assertJsonPath('data.status', 'cancelled')
            ->assertJsonPath('data.business_id', $this->business->id);
    }

    public function test_subscription_returns_all_subs(): void
    {
        Subscription::create([
            'business_id' => $this->business->id,
            'plan_id' => $this->plan->id,
            'status' => 'active',
            'starts_at' => now(),
        ]);

        $otherBusiness = Business::factory()->create([
            'owner_id' => $this->admin->id,
            'currency' => 'UGX',
            'status' => 'active',
        ]);
        Subscription::create([
            'business_id' => $otherBusiness->id,
            'plan_id' => $this->plan->id,
            'status' => 'active',
            'starts_at' => now(),
        ]);

        $response = $this->withHeader('Authorization', "Bearer $this->adminToken")
            ->getJson('/api/v1/subscriptions');

        $response->assertStatus(200)
            ->assertJsonStructure(['data']);
    }
}
