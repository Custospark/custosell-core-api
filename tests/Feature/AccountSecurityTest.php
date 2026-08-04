<?php

namespace Tests\Feature;

use App\Models\AccountVerificationCode;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AccountSecurityTest extends TestCase
{
    use RefreshDatabase;

    private function user(array $overrides = []): User
    {
        return User::factory()->create(array_merge([
            'email' => 'security@example.com',
            'password' => bcrypt('password123'),
            'email_verified_at' => null,
            'two_factor_enabled' => false,
        ], $overrides));
    }

    public function test_login_requires_email_verification_when_enabled(): void
    {
        config(['auth.verification.required' => true]);
        $this->user(['email_verified_at' => null]);

        $this->postJson('/api/v1/auth/login', [
            'email' => 'security@example.com',
            'password' => 'password123',
        ])->assertStatus(403)
            ->assertJson(['requires_email_verification' => true, 'email' => 'security@example.com']);

        $this->assertDatabaseHas('account_verification_codes', [
            'user_id' => User::first()->id,
            'purpose' => 'email_verification',
            'used_at' => null,
        ]);
    }

    public function test_login_skips_verification_when_disabled(): void
    {
        config(['auth.verification.required' => false]);
        $this->user(['email_verified_at' => null]);

        $this->postJson('/api/v1/auth/login', [
            'email' => 'security@example.com',
            'password' => 'password123',
        ])->assertStatus(200)
            ->assertJsonStructure(['user', 'token']);
    }

    public function test_login_requires_two_factor_when_enabled(): void
    {
        config(['auth.verification.required' => false]);
        $this->user(['email_verified_at' => now(), 'two_factor_enabled' => true]);

        $this->postJson('/api/v1/auth/login', [
            'email' => 'security@example.com',
            'password' => 'password123',
        ])->assertStatus(403)
            ->assertJson(['requires_two_factor' => true]);
    }

    public function test_verify_email_code_completes_login(): void
    {
        config(['auth.verification.required' => true]);
        $user = $this->user();

        $code = '123456';
        AccountVerificationCode::create([
            'user_id' => $user->id,
            'purpose' => 'email_verification',
            'code_hash' => Hash::make($code),
            'expires_at' => now()->addMinutes(10),
        ]);

        $this->postJson('/api/v1/auth/verify', [
            'email' => 'security@example.com',
            'purpose' => 'email_verification',
            'code' => $code,
        ])->assertStatus(200)
            ->assertJsonStructure(['user', 'token']);

        $this->assertNotNull($user->fresh()->email_verified_at);
        $this->assertDatabaseHas('account_audit_logs', [
            'user_id' => $user->id,
            'action' => 'email_verified',
        ]);
    }

    public function test_verify_with_invalid_code_returns_422(): void
    {
        $user = $this->user();
        AccountVerificationCode::create([
            'user_id' => $user->id,
            'purpose' => 'email_verification',
            'code_hash' => Hash::make('123456'),
            'expires_at' => now()->addMinutes(10),
        ]);

        $this->postJson('/api/v1/auth/verify', [
            'email' => 'security@example.com',
            'purpose' => 'email_verification',
            'code' => '999999',
        ])->assertStatus(422);
    }

    public function test_two_factor_toggle_and_audit(): void
    {
        $user = $this->user(['email_verified_at' => now()]);

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/auth/two-factor', ['enabled' => true])
            ->assertStatus(200)
            ->assertJson(['two_factor_enabled' => true]);

        $this->assertTrue((bool) $user->fresh()->two_factor_enabled);
        $this->assertDatabaseHas('account_audit_logs', [
            'user_id' => $user->id,
            'action' => 'two_factor_enabled',
        ]);
    }

    public function test_activity_feed_returns_login_events(): void
    {
        $user = $this->user(['email_verified_at' => now()]);

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/auth/two-factor', ['enabled' => true]);

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/auth/activity')
            ->assertOk()
            ->assertJsonStructure(['data' => [['id', 'action', 'at']]])
            ->assertJsonFragment(['action' => 'two_factor_enabled']);
    }

    public function test_send_verification_code_rejected_when_email_already_verified(): void
    {
        config(['auth.verification.required' => true]);
        $this->user(['email_verified_at' => now()]);

        $this->postJson('/api/v1/auth/verify/send', [
            'email' => 'security@example.com',
            'purpose' => 'email_verification',
        ])->assertStatus(422);
    }

    public function test_verify_email_already_verified_returns_422(): void
    {
        $this->user(['email_verified_at' => now()]);

        $this->postJson('/api/v1/auth/verify', [
            'email' => 'security@example.com',
            'purpose' => 'email_verification',
            'code' => '123456',
        ])->assertStatus(422);
    }

    public function test_email_verification_chains_into_two_factor_when_enabled(): void
    {
        config(['auth.verification.required' => true]);
        $user = $this->user(['two_factor_enabled' => true]);

        $code = '123456';
        AccountVerificationCode::create([
            'user_id' => $user->id,
            'purpose' => 'email_verification',
            'code_hash' => Hash::make($code),
            'expires_at' => now()->addMinutes(10),
        ]);

        $this->postJson('/api/v1/auth/verify', [
            'email' => 'security@example.com',
            'purpose' => 'email_verification',
            'code' => $code,
        ])->assertStatus(403)
            ->assertJson(['requires_two_factor' => true]);

        $this->assertNotNull($user->fresh()->email_verified_at);
        $this->assertDatabaseHas('account_verification_codes', [
            'user_id' => $user->id,
            'purpose' => 'two_factor',
            'used_at' => null,
        ]);
    }

    public function test_two_factor_verify_rejected_when_not_enabled(): void
    {
        $this->user(['email_verified_at' => now(), 'two_factor_enabled' => false]);

        $this->postJson('/api/v1/auth/verify', [
            'email' => 'security@example.com',
            'purpose' => 'two_factor',
            'code' => '123456',
        ])->assertStatus(422);
    }

    public function test_logout_is_audited(): void
    {
        $user = $this->user(['email_verified_at' => now()]);
        $token = $user->createToken('auth-token')->plainTextToken;

        $this->postJson('/api/v1/auth/logout', [], ['Authorization' => 'Bearer '.$token])
            ->assertOk();

        $this->assertDatabaseHas('account_audit_logs', [
            'user_id' => $user->id,
            'action' => 'logout',
        ]);
    }

    public function test_password_change_is_audited(): void
    {
        $user = $this->user(['email_verified_at' => now()]);
        $token = $user->createToken('auth-token')->plainTextToken;

        $this->putJson('/api/v1/auth/profile', [
            'name' => $user->name,
            'email' => $user->email,
            'password' => 'newpassword123',
            'password_confirmation' => 'newpassword123',
        ], ['Authorization' => 'Bearer '.$token])
            ->assertOk();

        $this->assertDatabaseHas('account_audit_logs', [
            'user_id' => $user->id,
            'action' => 'password_changed',
        ]);
    }
}
