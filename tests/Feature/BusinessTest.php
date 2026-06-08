<?php

namespace Tests\Feature;

use App\Models\{Business, Category, Customer, Plan, Product, Role, Sale, SaleItem, Shift, StockMovement, Subscription, ExpenseCategory, Expense, User};
use Database\Seeders\PlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BusinessTest extends TestCase
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

    public function test_register_business_with_user(): void
    {
        $response = $this->postJson('/api/v1/businesses/register', [
            'name' => 'My Shop',
            'email' => 'owner@shop.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ]);

        $response->assertStatus(201)
            ->assertJsonStructure(['id', 'name', 'owner_id', 'currency', 'status'])
            ->assertJsonPath('name', 'My Shop');
    }

    public function test_register_missing_name_returns_422(): void
    {
        $response = $this->postJson('/api/v1/businesses/register', [
            'email' => 'owner@shop.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['name']);
    }

    public function test_get_my_business(): void
    {
        $response = $this->withHeader('Authorization', "Bearer $this->adminToken")
            ->getJson('/api/v1/businesses/mine');

        $response->assertStatus(200)
            ->assertJsonStructure(['data' => ['id', 'name', 'currency']])
            ->assertJsonPath('data.id', $this->business->id);
    }

    public function test_get_my_business_without_token_returns_401(): void
    {
        $response = $this->getJson('/api/v1/businesses/mine');

        $response->assertStatus(401);
    }

    public function test_staff_can_get_business_by_membership_not_owner(): void
    {
        $response = $this->withHeader('Authorization', "Bearer $this->staffToken")
            ->getJson('/api/v1/businesses/mine');

        $response->assertStatus(200)
            ->assertJsonPath('data.id', $this->business->id);
    }

    public function test_get_specific_business(): void
    {
        $response = $this->withHeader('Authorization', "Bearer $this->adminToken")
            ->getJson("/api/v1/businesses/{$this->business->id}");

        $response->assertStatus(200)
            ->assertJsonStructure(['data' => ['id', 'name', 'currency', 'status']]);
    }

    public function test_update_business_settings(): void
    {
        $response = $this->withHeader('Authorization', "Bearer $this->adminToken")
            ->putJson("/api/v1/businesses/{$this->business->id}", [
                'name' => 'Updated Shop Name',
                'currency' => 'USD',
            ]);

        $response->assertStatus(200)
            ->assertJsonPath('data.name', 'Updated Shop Name')
            ->assertJsonPath('data.currency', 'USD');
    }

    public function test_register_sets_user_business_id(): void
    {
        $response = $this->postJson('/api/v1/businesses/register', [
            'name' => 'Another Shop',
            'email' => 'another@shop.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ]);

        $response->assertStatus(201);
        $businessId = $response->json('id');
        $ownerId = $response->json('owner_id');

        $user = User::find($ownerId);
        $this->assertEquals($businessId, $user->business_id);
    }

    public function test_default_currency_is_ugx(): void
    {
        $response = $this->postJson('/api/v1/businesses/register', [
            'name' => 'Currency Test Shop',
            'email' => 'currency@shop.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('currency', 'UGX');
    }
}
