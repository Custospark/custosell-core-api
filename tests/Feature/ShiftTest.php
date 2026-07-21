<?php

namespace Tests\Feature;

use App\Models\{Business, Category, Customer, Plan, Product, Role, Sale, SaleItem, Shift, StockMovement, Subscription, ExpenseCategory, Expense, User};
use Database\Seeders\PlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ShiftTest extends TestCase
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
        $this->setUpSubscription();
    }

    public function test_clock_in(): void
    {
        $response = $this->withHeader('Authorization', "Bearer $this->staffToken")
            ->postJson('/api/v1/shifts', [
                'clock_in' => now()->toDateTimeString(),
                'status' => 'active',
            ]);

        $response->assertStatus(201)
            ->assertJsonStructure(['id', 'clock_in', 'status'])
            ->assertJsonPath('status', 'active');
    }

    public function test_clock_in_twice_returns_error(): void
    {
        Shift::create([
            'business_id' => $this->business->id,
            'user_id' => $this->staff->id,
            'clock_in' => now(),
            'status' => 'active',
        ]);

        $response = $this->withHeader('Authorization', "Bearer $this->staffToken")
            ->postJson('/api/v1/shifts', [
                'clock_in' => now()->toDateTimeString(),
                'status' => 'active',
            ]);

        $response->assertStatus(201);
    }

    public function test_clock_out(): void
    {
        $shift = Shift::create([
            'business_id' => $this->business->id,
            'user_id' => $this->staff->id,
            'clock_in' => now()->subHours(8),
            'status' => 'active',
        ]);

        $response = $this->withHeader('Authorization', "Bearer $this->staffToken")
            ->putJson("/api/v1/shifts/{$shift->id}", [
                'clock_in' => $shift->clock_in->toDateTimeString(),
                'clock_out' => now()->toDateTimeString(),
                'status' => 'completed',
            ]);

        $response->assertStatus(200)
            ->assertJsonPath('data.status', 'completed');
    }

    public function test_get_active_shift(): void
    {
        Shift::create([
            'business_id' => $this->business->id,
            'user_id' => $this->staff->id,
            'clock_in' => now(),
            'status' => 'active',
        ]);

        $response = $this->withHeader('Authorization', "Bearer $this->staffToken")
            ->getJson('/api/v1/shifts/active');

        $response->assertStatus(200)
            ->assertJsonStructure(['id', 'status'])
            ->assertJsonPath('status', 'active');
    }

    public function test_list_shifts(): void
    {
        Shift::create([
            'business_id' => $this->business->id,
            'user_id' => $this->staff->id,
            'clock_in' => now()->subDays(1),
            'clock_out' => now()->subDays(1)->addHours(8),
            'status' => 'completed',
        ]);

        $response = $this->withHeader('Authorization', "Bearer $this->adminToken")
            ->getJson('/api/v1/shifts');

        $response->assertStatus(200)
            ->assertJsonStructure(['data']);
    }

    public function test_shifts_scoped_per_business(): void
    {
        Shift::create([
            'business_id' => $this->business->id,
            'user_id' => $this->staff->id,
            'clock_in' => now(),
            'status' => 'active',
        ]);

        $otherBusiness = Business::factory()->create([
            'owner_id' => $this->admin->id,
            'currency' => 'UGX',
            'status' => 'active',
        ]);
        Shift::create([
            'business_id' => $otherBusiness->id,
            'user_id' => $this->admin->id,
            'clock_in' => now(),
            'status' => 'active',
        ]);

        $response = $this->withHeader('Authorization', "Bearer $this->staffToken")
            ->getJson('/api/v1/shifts');

        $response->assertStatus(200);
        $userIds = collect($response->json('data'))->pluck('user_id')->toArray();
        $this->assertContains($this->staff->id, $userIds);
        $this->assertNotContains($this->admin->id, $userIds);
    }
}
