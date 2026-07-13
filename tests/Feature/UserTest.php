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

    public function test_delete_staff_rejected_use_detach(): void
    {
        $response = $this->withHeader('Authorization', "Bearer $this->adminToken")
            ->deleteJson("/api/v1/users/{$this->staff->id}");

        $response->assertStatus(422);
        $this->assertDatabaseHas('users', [
            'id' => $this->staff->id,
            'deleted_at' => null,
            'business_id' => $this->business->id,
        ]);
    }

    public function test_detach_staff_clears_membership_keeps_login(): void
    {
        $this->staff->tokens()->delete();
        $this->staff->createToken('staff');

        $response = $this->withHeader('Authorization', "Bearer $this->adminToken")
            ->postJson("/api/v1/users/{$this->staff->id}/detach");

        $response->assertOk()
            ->assertJsonPath('data.detached', true)
            ->assertJsonPath('data.id', $this->staff->id);

        $this->staff->refresh();
        $this->assertNull($this->staff->business_id);
        $this->assertNull($this->staff->role_id);
        $this->assertSame([], $this->staff->modules ?? []);
        $this->assertNull($this->staff->deleted_at);
        $this->assertTrue((bool) $this->staff->is_active);
        $this->assertSame(0, $this->staff->tokens()->count());
    }

    public function test_lookup_and_attach_unattached_user(): void
    {
        $free = User::factory()->create([
            'email' => 'free.attach@example.com',
            'business_id' => null,
            'role_id' => null,
            'modules' => [],
            'is_active' => true,
        ]);

        $this->withHeader('Authorization', "Bearer $this->adminToken")
            ->getJson('/api/v1/users/lookup?email=free.attach@example.com')
            ->assertOk()
            ->assertJsonPath('data.status', 'unattached')
            ->assertJsonPath('data.user.id', $free->id);

        $this->withHeader('Authorization', "Bearer $this->adminToken")
            ->postJson('/api/v1/users/attach', [
                'email' => 'free.attach@example.com',
                'role_id' => $this->staff->role_id,
                'modules' => ['sales'],
            ])
            ->assertOk()
            ->assertJsonPath('data.email', 'free.attach@example.com')
            ->assertJsonPath('data.business_id', $this->business->id);

        $free->refresh();
        $this->assertSame($this->business->id, $free->business_id);
        $this->assertContains('sales', $free->modules ?? []);
    }

    public function test_attach_rejects_other_business_email(): void
    {
        $otherOwner = User::factory()->create(['is_active' => true]);
        $otherBusiness = Business::factory()->create([
            'owner_id' => $otherOwner->id,
            'currency' => 'UGX',
            'status' => 'active',
        ]);
        $otherOwner->business_id = $otherBusiness->id;
        $otherOwner->save();

        $taken = User::factory()->create([
            'email' => 'taken.elsewhere@example.com',
            'business_id' => $otherBusiness->id,
            'is_active' => true,
        ]);

        $this->withHeader('Authorization', "Bearer $this->adminToken")
            ->getJson('/api/v1/users/lookup?email=taken.elsewhere@example.com')
            ->assertOk()
            ->assertJsonPath('data.status', 'other_business');

        $this->withHeader('Authorization', "Bearer $this->adminToken")
            ->postJson('/api/v1/users/attach', [
                'email' => $taken->email,
                'role_id' => $this->staff->role_id,
                'modules' => ['sales'],
            ])
            ->assertStatus(409);
    }

    public function test_update_staff_rejects_is_active(): void
    {
        $response = $this->withHeader('Authorization', "Bearer $this->adminToken")
            ->putJson("/api/v1/users/{$this->staff->id}", [
                'name' => $this->staff->name,
                'email' => $this->staff->email,
                'phone' => $this->staff->phone,
                'role_id' => $this->staff->role_id,
                'is_active' => false,
            ]);

        // is_active stripped from request rules — if present as unexpected, may be ignored;
        // force via service by sending only when validated. Without rule, Laravel strips unknown.
        // Explicitly assert staff stays active when client tries deactivate via modules-only path.
        $this->staff->refresh();
        $this->assertTrue((bool) $this->staff->is_active);

        // Direct service rejection when is_active in validated data:
        $this->expectException(\Illuminate\Validation\ValidationException::class);
        app(\App\Services\Contracts\UserServiceInterface::class)->update(
            $this->staff->id,
            $this->business->id,
            $this->admin->id,
            [
                'name' => $this->staff->name,
                'email' => $this->staff->email,
                'is_active' => false,
            ],
        );
    }

    public function test_update_staff_allows_preserved_admin_role(): void
    {
        $response = $this->withHeader('Authorization', "Bearer $this->adminToken")
            ->putJson("/api/v1/users/{$this->admin->id}", [
                'name' => 'Updated Admin',
                'email' => $this->admin->email,
                'phone' => '+256700000002',
                'role_id' => $this->admin->role_id,
            ]);

        $response->assertStatus(200)
            ->assertJsonPath('data.name', 'Updated Admin')
            ->assertJsonPath('data.role.name', 'Admin');
    }

    public function test_update_business_owner_without_assigned_role(): void
    {
        $this->admin->forceFill(['role_id' => null])->save();

        $response = $this->withHeader('Authorization', "Bearer $this->adminToken")
            ->putJson("/api/v1/users/{$this->admin->id}", [
                'name' => 'Updated Owner',
                'email' => $this->admin->email,
                'phone' => '+256700000003',
                'role_id' => null,
            ]);

        $response->assertStatus(200)
            ->assertJsonPath('data.name', 'Updated Owner')
            ->assertJsonPath('data.role_id', null);
    }

    public function test_update_staff_rejects_other_business_role(): void
    {
        $otherBusiness = Business::factory()->create([
            'owner_id' => $this->admin->id,
            'currency' => 'UGX',
            'status' => 'active',
        ]);
        $otherRole = Role::create([
            'business_id' => $otherBusiness->id,
            'name' => 'Other Staff',
            'slug' => 'other-staff',
            'permissions' => [],
        ]);

        $response = $this->withHeader('Authorization', "Bearer $this->adminToken")
            ->putJson("/api/v1/users/{$this->staff->id}", [
                'name' => $this->staff->name,
                'email' => $this->staff->email,
                'phone' => $this->staff->phone,
                'role_id' => $otherRole->id,
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['role_id']);
    }

    public function test_update_staff_rejects_admin_role_change(): void
    {
        $response = $this->withHeader('Authorization', "Bearer $this->adminToken")
            ->putJson("/api/v1/users/{$this->admin->id}", [
                'name' => $this->admin->name,
                'email' => $this->admin->email,
                'phone' => $this->admin->phone,
                'role_id' => $this->staff->role_id,
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['role_id']);
    }

    public function test_cannot_detach_self(): void
    {
        $response = $this->withHeader('Authorization', "Bearer $this->adminToken")
            ->postJson("/api/v1/users/{$this->admin->id}/detach");

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['user']);
    }

    public function test_cannot_detach_business_owner(): void
    {
        $manager = User::factory()->create([
            'business_id' => $this->business->id,
            'role_id' => $this->staff->role_id,
            'is_active' => true,
            'modules' => ['settings'],
        ]);
        $managerToken = $manager->createToken('manager')->plainTextToken;

        $response = $this->withHeader('Authorization', "Bearer $managerToken")
            ->postJson("/api/v1/users/{$this->admin->id}/detach");

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['user']);
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
