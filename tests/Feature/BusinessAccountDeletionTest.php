<?php

namespace Tests\Feature;

use App\Models\{Business, Product, Role, User};
use Database\Seeders\PlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BusinessAccountDeletionTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;
    protected User $staff;
    protected Business $business;
    protected string $adminToken;
    protected string $staffToken;
    protected string $password = 'correct-password';

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(PlanSeeder::class);

        $this->admin = User::factory()->create([
            'is_active' => true,
            'password' => bcrypt($this->password),
        ]);
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
            'permissions' => ['settings.view' => true, 'settings.edit' => true],
        ]);
        $this->admin->role_id = $adminRole->id;
        $this->admin->save();

        $this->staff = User::factory()->create([
            'business_id' => $this->business->id,
            'is_active' => true,
        ]);
        $this->staffToken = $this->staff->createToken('staff')->plainTextToken;

        Product::factory()->create(['business_id' => $this->business->id]);
    }

    public function test_delete_account_with_correct_password_returns_200(): void
    {
        $response = $this->withHeader('Authorization', "Bearer $this->adminToken")
            ->deleteJson('/api/v1/businesses/account', [
                'password' => $this->password,
            ]);

        $response->assertStatus(200)
            ->assertJsonPath('logged_out', true)
            ->assertJsonStructure(['message', 'logged_out']);
    }

    public function test_delete_account_soft_deletes_business(): void
    {
        $this->withHeader('Authorization', "Bearer $this->adminToken")
            ->deleteJson('/api/v1/businesses/account', [
                'password' => $this->password,
            ]);

        $this->assertSoftDeleted('businesses', ['id' => $this->business->id]);
    }

    public function test_delete_account_clears_products(): void
    {
        $this->withHeader('Authorization', "Bearer $this->adminToken")
            ->deleteJson('/api/v1/businesses/account', [
                'password' => $this->password,
            ]);

        $this->assertEquals(0, Product::where('business_id', $this->business->id)->count());
    }

    public function test_delete_account_with_wrong_password_returns_422(): void
    {
        $response = $this->withHeader('Authorization', "Bearer $this->adminToken")
            ->deleteJson('/api/v1/businesses/account', [
                'password' => 'wrong-password',
            ]);

        $response->assertStatus(422);
    }

    public function test_delete_account_without_token_returns_401(): void
    {
        $response = $this->deleteJson('/api/v1/businesses/account', [
            'password' => $this->password,
        ]);

        $response->assertStatus(401);
    }

    public function test_delete_account_by_staff_returns_403(): void
    {
        $response = $this->withHeader('Authorization', "Bearer $this->staffToken")
            ->deleteJson('/api/v1/businesses/account', [
                'password' => $this->password,
            ]);

        $response->assertStatus(403);
    }

    public function test_delete_account_revokes_token(): void
    {
        $this->withHeader('Authorization', "Bearer $this->adminToken")
            ->deleteJson('/api/v1/businesses/account', [
                'password' => $this->password,
            ]);

        $response = $this->withHeader('Authorization', "Bearer $this->adminToken")
            ->getJson('/api/v1/businesses/mine');

        $response->assertStatus(401);
    }
}
