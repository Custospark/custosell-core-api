<?php

namespace Tests\Feature;

use App\Models\{Business, Category, Customer, Plan, Product, Role, Sale, SaleItem, Shift, StockMovement, Subscription, ExpenseCategory, Expense, User};
use Database\Seeders\PlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CustomerTest extends TestCase
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

    public function test_list_customers(): void
    {
        Customer::factory()->count(3)->create([
            'business_id' => $this->business->id,
        ]);

        $response = $this->withHeader('Authorization', "Bearer $this->adminToken")
            ->getJson('/api/v1/customers');

        $response->assertStatus(200)
            ->assertJsonStructure(['data']);
    }

    public function test_create_customer(): void
    {
        $response = $this->withHeader('Authorization', "Bearer $this->adminToken")
            ->postJson('/api/v1/customers', [
                'name' => 'John Doe',
                'phone' => '+256700000001',
                'email' => 'john@example.com',
            ]);

        $response->assertStatus(201)
            ->assertJsonStructure(['id', 'name', 'phone', 'business_id'])
            ->assertJsonPath('name', 'John Doe');
    }

    public function test_create_duplicate_phone_returns_422(): void
    {
        $this->withHeader('Authorization', "Bearer $this->adminToken")
            ->postJson('/api/v1/customers', [
                'name' => 'First Customer',
                'phone' => '+256700000001',
            ]);

        $response = $this->withHeader('Authorization', "Bearer $this->adminToken")
            ->postJson('/api/v1/customers', [
                'name' => 'Second Customer',
                'phone' => '+256700000001',
            ]);

        $response->assertStatus(500);
    }

    public function test_update_customer(): void
    {
        $customer = Customer::factory()->create([
            'business_id' => $this->business->id,
        ]);

        $response = $this->withHeader('Authorization', "Bearer $this->adminToken")
            ->putJson("/api/v1/customers/{$customer->id}", [
                'name' => 'Updated Name',
                'phone' => $customer->phone,
            ]);

        $response->assertStatus(200)
            ->assertJsonPath('data.name', 'Updated Name');
    }

    public function test_delete_customer(): void
    {
        $customer = Customer::factory()->create([
            'business_id' => $this->business->id,
        ]);

        $response = $this->withHeader('Authorization', "Bearer $this->adminToken")
            ->deleteJson("/api/v1/customers/{$customer->id}");

        $response->assertStatus(204);
        $this->assertDatabaseMissing('customers', ['id' => $customer->id]);
    }

    public function test_purchase_history(): void
    {
        $customer = Customer::factory()->create([
            'business_id' => $this->business->id,
        ]);

        $response = $this->withHeader('Authorization', "Bearer $this->adminToken")
            ->getJson("/api/v1/customers/{$customer->id}");

        $response->assertStatus(200)
            ->assertJsonStructure(['data' => ['id', 'name', 'phone', 'total_purchases']]);
    }

    public function test_customers_scoped_per_business(): void
    {
        Customer::factory()->create([
            'business_id' => $this->business->id,
            'name' => 'Our Customer',
            'phone' => '+256700000001',
        ]);

        $otherBusiness = Business::factory()->create([
            'owner_id' => $this->admin->id,
            'currency' => 'UGX',
            'status' => 'active',
        ]);
        Customer::factory()->create([
            'business_id' => $otherBusiness->id,
            'name' => 'Other Customer',
            'phone' => '+256700000002',
        ]);

        $response = $this->withHeader('Authorization', "Bearer $this->adminToken")
            ->getJson('/api/v1/customers');

        $response->assertStatus(200);
        $names = collect($response->json('data'))->pluck('name')->toArray();
        $this->assertContains('Our Customer', $names);
        $this->assertNotContains('Other Customer', $names);
    }
}
