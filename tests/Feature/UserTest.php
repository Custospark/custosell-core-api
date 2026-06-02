<?php

namespace Tests\Feature;

use App\Models\{Business, Category, Customer, Plan, Product, Role, Sale, SaleItem, Shift, StockMovement, Subscription, ExpenseCategory, Expense, User};
use Database\Seeders\PlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UserTest extends TestCase
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

    public function test_list_staff_users(): void
    {
        $response = $this->withHeader('Authorization', "Bearer $this->adminToken")
            ->getJson('/api/v1/users');

        $response->assertStatus(200)
            ->assertJsonStructure(['data']);
    }

    public function test_get_single_user(): void
    {
        $response = $this->withHeader('Authorization', "Bearer $this->adminToken")
            ->getJson("/api/v1/users/{$this->staff->id}");

        $response->assertStatus(200)
            ->assertJsonStructure(['data' => ['id', 'name', 'email']])
            ->assertJsonPath('data.id', $this->staff->id);
    }

    public function test_create_staff_user(): void
    {
        $response = $this->withHeader('Authorization', "Bearer $this->adminToken")
            ->postJson('/api/v1/users', [
                'name' => 'New Staff',
                'email' => 'newstaff@example.com',
                'password' => 'password123',
                'password_confirmation' => 'password123',
                'phone' => '+256700000001',
            ]);

        $response->assertStatus(201)
            ->assertJsonStructure(['id', 'name', 'email', 'business_id'])
            ->assertJsonPath('name', 'New Staff');
    }

    public function test_create_staff_with_role(): void
    {
        $role = $this->staff->role;

        $response = $this->withHeader('Authorization', "Bearer $this->adminToken")
            ->postJson('/api/v1/users', [
                'name' => 'Role Staff',
                'email' => 'rolestaff@example.com',
                'password' => 'password123',
                'password_confirmation' => 'password123',
                'role_id' => $role->id,
            ]);

        $response->assertStatus(201);

        $userData = $response->json();
        if (isset($userData['data'])) {
            $this->assertEquals($role->id, $userData['data']['role_id']);
        } else {
            $this->assertEquals($role->id, $userData['role_id']);
        }
    }

    public function test_create_staff_duplicate_email_returns_422(): void
    {
        $this->withHeader('Authorization', "Bearer $this->adminToken")
            ->postJson('/api/v1/users', [
                'name' => 'First User',
                'email' => 'duplicate@example.com',
                'password' => 'password123',
                'password_confirmation' => 'password123',
            ]);

        $response = $this->withHeader('Authorization', "Bearer $this->adminToken")
            ->postJson('/api/v1/users', [
                'name' => 'Second User',
                'email' => 'duplicate@example.com',
                'password' => 'password123',
                'password_confirmation' => 'password123',
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['email']);
    }

    public function test_delete_staff(): void
    {
        $response = $this->withHeader('Authorization', "Bearer $this->adminToken")
            ->deleteJson("/api/v1/users/{$this->staff->id}");

        $response->assertStatus(204);
        $this->assertSoftDeleted('users', ['id' => $this->staff->id]);
    }

    public function test_created_by_set_on_creation(): void
    {
        $response = $this->withHeader('Authorization', "Bearer $this->adminToken")
            ->postJson('/api/v1/users', [
                'name' => 'Tracked Staff',
                'email' => 'tracked@example.com',
                'password' => 'password123',
                'password_confirmation' => 'password123',
            ]);

        $response->assertStatus(201);
        $userData = $response->json();
        $userId = $userData['data']['id'] ?? $userData['id'];
        $user = User::find($userId);
        $this->assertNotNull($user);
        $this->assertEquals($this->admin->id, $user->created_by);
    }

    public function test_cannot_see_other_business_users(): void
    {
        $otherBusiness = Business::factory()->create([
            'owner_id' => $this->admin->id,
            'currency' => 'UGX',
            'status' => 'active',
        ]);
        $otherUser = User::factory()->create([
            'business_id' => $otherBusiness->id,
            'name' => 'Other Business User',
            'is_active' => true,
        ]);

        $response = $this->withHeader('Authorization', "Bearer $this->adminToken")
            ->getJson('/api/v1/users');

        $response->assertStatus(200);
        $names = collect($response->json('data'))->pluck('name')->toArray();
        $this->assertNotContains('Other Business User', $names);
    }
}
