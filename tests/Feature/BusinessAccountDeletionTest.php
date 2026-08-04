<?php

namespace Tests\Feature;

use App\Models\{AccountVerificationCode, Business, Product, Role, User};
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

    protected function initiateDeletion(string $token, array $payload = []): \Illuminate\Testing\TestResponse
    {
        return $this->withHeader('Authorization', "Bearer $token")
            ->postJson('/api/v1/businesses/account/initiate', $payload ?: ['password' => $this->password]);
    }

    protected function issueCode(): void
    {
        $code = '123456';
        AccountVerificationCode::create([
            'user_id' => $this->admin->id,
            'purpose' => 'delete_account',
            'code_hash' => bcrypt($code),
            'context' => ['delete_account' => true],
            'expires_at' => now()->addMinutes(10),
        ]);
    }

    public function test_initiate_with_correct_password_returns_200_and_requires_confirmation(): void
    {
        $response = $this->initiateDeletion($this->adminToken);

        $response->assertStatus(200)
            ->assertJsonPath('requires_delete_confirmation', true)
            ->assertJsonStructure(['message', 'requires_delete_confirmation']);
    }

    public function test_initiate_with_wrong_password_returns_422(): void
    {
        $response = $this->initiateDeletion($this->adminToken, ['password' => 'wrong-password']);

        $response->assertStatus(422);
    }

    public function test_initiate_without_token_returns_401(): void
    {
        $response = $this->postJson('/api/v1/businesses/account/initiate', ['password' => $this->password]);

        $response->assertStatus(401);
    }

    public function test_initiate_by_staff_returns_403(): void
    {
        $response = $this->initiateDeletion($this->staffToken);

        $response->assertStatus(403);
    }

    public function test_confirm_with_valid_code_returns_200(): void
    {
        $this->initiateDeletion($this->adminToken);
        $this->issueCode();

        $response = $this->withHeader('Authorization', "Bearer $this->adminToken")
            ->postJson('/api/v1/businesses/account/confirm', ['code' => '123456']);

        $response->assertStatus(200)
            ->assertJsonPath('logged_out', true)
            ->assertJsonStructure(['message', 'logged_out']);
    }

    public function test_confirm_with_valid_code_soft_deletes_business_and_clears_products(): void
    {
        $this->initiateDeletion($this->adminToken);
        $this->issueCode();

        $this->withHeader('Authorization', "Bearer $this->adminToken")
            ->postJson('/api/v1/businesses/account/confirm', ['code' => '123456']);

        $this->assertSoftDeleted('businesses', ['id' => $this->business->id]);
        $this->assertEquals(0, Product::where('business_id', $this->business->id)->count());
    }

    public function test_confirm_with_wrong_code_returns_422_and_does_not_delete(): void
    {
        $this->initiateDeletion($this->adminToken);
        $this->issueCode();

        $response = $this->withHeader('Authorization', "Bearer $this->adminToken")
            ->postJson('/api/v1/businesses/account/confirm', ['code' => '000000']);

        $response->assertStatus(422);
        $this->assertNotSoftDeleted('businesses', ['id' => $this->business->id]);
    }

    public function test_confirm_without_issued_code_returns_422(): void
    {
        $response = $this->withHeader('Authorization', "Bearer $this->adminToken")
            ->postJson('/api/v1/businesses/account/confirm', ['code' => '123456']);

        $response->assertStatus(422);
    }

    public function test_confirm_code_is_single_use(): void
    {
        $this->initiateDeletion($this->adminToken);
        $this->issueCode();

        $this->withHeader('Authorization', "Bearer $this->adminToken")
            ->postJson('/api/v1/businesses/account/confirm', ['code' => '123456']);

        $this->assertNotNull(
            AccountVerificationCode::where('user_id', $this->admin->id)
                ->where('purpose', 'delete_account')
                ->orderByDesc('id')
                ->value('used_at'),
        );
    }

    public function test_confirm_without_token_returns_401(): void
    {
        $response = $this->postJson('/api/v1/businesses/account/confirm', ['code' => '123456']);

        $response->assertStatus(401);
    }

    public function test_confirm_by_staff_returns_403(): void
    {
        $this->issueCode();

        $response = $this->withHeader('Authorization', "Bearer $this->staffToken")
            ->postJson('/api/v1/businesses/account/confirm', ['code' => '123456']);

        $response->assertStatus(403);
    }

    public function test_confirm_revokes_token(): void
    {
        $this->initiateDeletion($this->adminToken);
        $this->issueCode();

        $this->withHeader('Authorization', "Bearer $this->adminToken")
            ->postJson('/api/v1/businesses/account/confirm', ['code' => '123456']);

        $this->app['auth']->forgetGuards();

        $response = $this->withHeader('Authorization', "Bearer $this->adminToken")
            ->getJson('/api/v1/businesses/mine');

        $response->assertStatus(401);
    }
}
