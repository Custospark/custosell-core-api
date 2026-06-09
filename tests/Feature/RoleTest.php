<?php

namespace Tests\Feature;

use App\Models\{Business, Category, Customer, Plan, Product, Role, Sale, SaleItem, Shift, StockMovement, Subscription, ExpenseCategory, Expense, User};
use Database\Seeders\{PlanSeeder, SystemRoleSeeder};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RoleTest extends TestCase
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
        $this->seed(SystemRoleSeeder::class);

        $this->admin = User::factory()->create(['is_active' => true]);
        $this->adminToken = $this->admin->createToken('admin')->plainTextToken;

        $this->business = Business::factory()->create([
            'owner_id' => $this->admin->id,
            'currency' => 'UGX',
            'status' => 'active',
        ]);
        $this->admin->business_id = $this->business->id;
        $this->admin->save();

        $adminRole = Role::query()->whereNull('business_id')->where('slug', 'admin')->firstOrFail();
        $this->admin->role_id = $adminRole->id;
        $this->admin->save();

        $this->staff = User::factory()->create([
            'business_id' => $this->business->id,
            'is_active' => true,
        ]);
        $staffRole = Role::query()->whereNull('business_id')->where('slug', 'staff')->firstOrFail();
        $this->staff->role_id = $staffRole->id;
        $this->staff->save();
        $this->staffToken = $this->staff->createToken('staff')->plainTextToken;
    }

    public function test_list_roles_for_business(): void
    {
        $response = $this->withHeader('Authorization', "Bearer $this->adminToken")
            ->getJson('/api/v1/roles');

        $response->assertStatus(200)
            ->assertJsonStructure(['data']);
    }

    public function test_get_single_role(): void
    {
        $role = Role::query()->whereNull('business_id')->where('slug', 'admin')->firstOrFail();

        $response = $this->withHeader('Authorization', "Bearer $this->adminToken")
            ->getJson("/api/v1/roles/{$role->id}");

        $response->assertStatus(200)
            ->assertJsonStructure(['data' => ['id', 'name', 'slug', 'permissions']]);
    }

    public function test_create_role(): void
    {
        $response = $this->withHeader('Authorization', "Bearer $this->adminToken")
            ->postJson('/api/v1/roles', [
                'name' => 'Manager',
                'slug' => 'manager',
                'description' => 'Can manage sales and inventory',
                'permissions' => [
                    'sales.create' => true,
                    'sales.view' => true,
                    'inventory.view' => true,
                ],
            ]);

        $response->assertStatus(201)
            ->assertJsonStructure(['id', 'name', 'slug', 'permissions'])
            ->assertJsonPath('name', 'Manager');
    }

    public function test_create_role_without_permissions_succeeds(): void
    {
        $response = $this->withHeader('Authorization', "Bearer $this->adminToken")
            ->postJson('/api/v1/roles', [
                'name' => 'Label Only Role',
                'slug' => 'label-only-role',
                'description' => 'Access is controlled by staff module checkboxes',
            ]);

        $response->assertStatus(201)
            ->assertJsonPath('name', 'Label Only Role')
            ->assertJsonPath('permissions', []);
    }

    public function test_update_role_permissions(): void
    {
        $role = Role::create([
            'business_id' => $this->business->id,
            'name' => 'Custom Staff',
            'slug' => 'custom-staff',
            'permissions' => ['sales.create' => true, 'sales.view' => true],
        ]);

        $response = $this->withHeader('Authorization', "Bearer $this->adminToken")
            ->putJson("/api/v1/roles/{$role->id}", [
                'name' => 'Custom Staff',
                'slug' => 'custom-staff',
                'permissions' => [
                    'sales.create' => true,
                    'sales.view' => true,
                    'sales.refund' => true,
                    'inventory.view' => true,
                ],
            ]);

        $response->assertStatus(200);

        $permissions = $response->json('data.permissions');
        $this->assertTrue($permissions['sales.refund']);
        $this->assertTrue($permissions['sales.create']);
    }

    public function test_delete_role(): void
    {
        $role = Role::create([
            'business_id' => $this->business->id,
            'name' => 'Temporary',
            'slug' => 'temporary',
            'permissions' => ['sales.view' => true],
        ]);

        $response = $this->withHeader('Authorization', "Bearer $this->adminToken")
            ->deleteJson("/api/v1/roles/{$role->id}");

        $response->assertStatus(204);
        $this->assertDatabaseMissing('roles', ['id' => $role->id]);
    }

    public function test_cannot_update_system_role(): void
    {
        $role = Role::query()->whereNull('business_id')->where('slug', 'staff')->firstOrFail();

        $response = $this->withHeader('Authorization', "Bearer $this->adminToken")
            ->putJson("/api/v1/roles/{$role->id}", [
                'name' => 'Staff',
                'slug' => 'staff',
                'permissions' => ['sales.create' => true],
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['role']);
    }

    public function test_cannot_delete_system_role(): void
    {
        $role = Role::query()->whereNull('business_id')->where('slug', 'staff')->firstOrFail();

        $response = $this->withHeader('Authorization', "Bearer $this->adminToken")
            ->deleteJson("/api/v1/roles/{$role->id}");

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['role']);
    }

    public function test_roles_scoped_per_business(): void
    {
        $otherBusiness = Business::factory()->create([
            'owner_id' => $this->admin->id,
            'currency' => 'UGX',
            'status' => 'active',
        ]);

        Role::create([
            'business_id' => $otherBusiness->id,
            'name' => 'Other Role',
            'slug' => 'other-role',
            'permissions' => ['sales.view' => true],
        ]);

        $response = $this->withHeader('Authorization', "Bearer $this->adminToken")
            ->getJson('/api/v1/roles');

        $response->assertStatus(200);
        $names = collect($response->json('data'))->pluck('name')->toArray();
        $this->assertContains('Admin', $names);
        $this->assertContains('Staff', $names);
        $this->assertContains('Manager', $names);
        $this->assertNotContains('Other Role', $names);
    }

    public function test_system_admin_role_exists(): void
    {
        $this->assertDatabaseHas('roles', [
            'business_id' => null,
            'slug' => 'admin',
        ]);
    }

    public function test_system_staff_role_exists(): void
    {
        $this->assertDatabaseHas('roles', [
            'business_id' => null,
            'slug' => 'staff',
            'is_default' => true,
        ]);
    }

    public function test_list_includes_system_flag(): void
    {
        $response = $this->withHeader('Authorization', "Bearer $this->adminToken")
            ->getJson('/api/v1/roles');

        $response->assertStatus(200);
        $staff = collect($response->json('data'))->firstWhere('slug', 'staff');
        $this->assertTrue($staff['is_system']);
    }

    public function test_permissions_is_object_or_array(): void
    {
        $role = Role::query()->whereNull('business_id')->where('slug', 'admin')->firstOrFail();

        $this->assertIsArray($role->permissions);
        $this->assertArrayHasKey('sales.create', $role->permissions);
    }
}
