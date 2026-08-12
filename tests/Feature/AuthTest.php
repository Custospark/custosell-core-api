<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\ChartOfAccount;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuthTest extends TestCase
{
    use RefreshDatabase;

    public function test_can_register(): void
    {
        $response = $this->postJson('/api/v1/auth/register', [
            'name' => 'Test User',
            'email' => 'test@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ]);

        $response->assertStatus(201)
            ->assertJsonStructure(['user' => ['id', 'name', 'email'], 'token']);
    }

    public function test_register_with_existing_email_returns_422(): void
    {
        User::factory()->create(['email' => 'test@example.com']);

        $response = $this->postJson('/api/v1/auth/register', [
            'name' => 'Another User',
            'email' => 'test@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['email']);
    }

    public function test_register_with_short_password_returns_422(): void
    {
        $response = $this->postJson('/api/v1/auth/register', [
            'name' => 'Test User',
            'email' => 'test@example.com',
            'password' => '12345',
            'password_confirmation' => '12345',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['password']);
    }

    public function test_personal_registration_seeds_chart_of_accounts(): void
    {
        $this->postJson('/api/v1/auth/register', [
            'name' => 'Chart User',
            'email' => 'chart@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ])->assertStatus(201);

        $business = Business::where('business_type', 'personal')->first();
        $this->assertNotNull($business);
        $this->assertTrue(ChartOfAccount::where('business_id', $business->id)->where('code', '1101')->exists());
        $this->assertTrue(ChartOfAccount::where('business_id', $business->id)->where('code', '4100')->exists());
        $this->assertTrue(ChartOfAccount::where('business_id', $business->id)->where('code', '6101')->exists());
    }

    public function test_personal_registration_seeds_default_pipeline_board(): void
    {
        $this->postJson('/api/v1/auth/register', [
            'name' => 'Board User',
            'email' => 'board@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ])->assertStatus(201);

        $business = Business::where('business_type', 'personal')->first();
        $this->assertNotNull($business);

        $board = \App\Models\PipelineBoard::where('business_id', $business->id)->first();
        $this->assertNotNull($board, 'Personal account should be seeded with a default board');
        $this->assertTrue((bool) $board->is_default);
        $this->assertNotEmpty($board->code);

        // Sample kanban columns + guiding cards so the board is never empty
        $this->assertGreaterThanOrEqual(3, \App\Models\PipelineStage::where('board_id', $board->id)->count());
        $this->assertGreaterThanOrEqual(1, \App\Models\PipelineLead::where('board_id', $board->id)->count());
    }

    public function test_can_login_with_valid_credentials(): void
    {
        User::factory()->create([
            'email' => 'test@example.com',
            'password' => bcrypt('password123'),
        ]);

        $response = $this->postJson('/api/v1/auth/login', [
            'email' => 'test@example.com',
            'password' => 'password123',
        ]);

        $response->assertStatus(200)
            ->assertJsonStructure(['user', 'token']);
    }

    public function test_login_with_wrong_password_returns_401(): void
    {
        User::factory()->create([
            'email' => 'test@example.com',
            'password' => bcrypt('password123'),
        ]);

        $response = $this->postJson('/api/v1/auth/login', [
            'email' => 'test@example.com',
            'password' => 'wrongpassword',
        ]);

        $response->assertStatus(401);
    }

    public function test_login_with_non_existent_email_returns_401(): void
    {
        $response = $this->postJson('/api/v1/auth/login', [
            'email' => 'nonexistent@example.com',
            'password' => 'password123',
        ]);

        $response->assertStatus(401);
    }

    public function test_can_logout_authenticated_user(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('test-token')->plainTextToken;

        $response = $this->withHeader('Authorization', "Bearer $token")
            ->postJson('/api/v1/auth/logout');

        $response->assertStatus(200)
            ->assertJson(['message' => 'Logged out']);
    }

    public function test_logout_without_token_returns_401(): void
    {
        $response = $this->postJson('/api/v1/auth/logout');

        $response->assertStatus(401);
    }

    public function test_can_get_current_user_when_authenticated(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('test-token')->plainTextToken;

        $response = $this->withHeader('Authorization', "Bearer $token")
            ->getJson('/api/v1/auth/me');

        $response->assertStatus(200)
            ->assertJson(['data' => ['id' => $user->id, 'email' => $user->email]]);
    }

    public function test_get_current_user_without_token_returns_401(): void
    {
        $response = $this->getJson('/api/v1/auth/me');

        $response->assertStatus(401);
    }
}
