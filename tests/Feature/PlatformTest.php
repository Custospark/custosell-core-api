<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class PlatformTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->artisan('migrate', ['--force' => true]);
    }

    public function test_platform_overview_requires_platform_permission(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/platform/overview')
            ->assertForbidden();
    }

    public function test_platform_admin_can_view_overview(): void
    {
        $user = User::factory()->create();
        $user->assignRole('platform-admin');

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/platform/overview')
            ->assertOk()
            ->assertJsonStructure(['data' => ['businesses', 'users', 'system', 'pricing_insights', 'top_businesses_30d']]);
    }

    public function test_configured_admin_email_gets_role_on_business_registration(): void
    {
        config(['platform.admin_emails' => ['founder@custospark.com']]);

        $this->postJson('/api/v1/businesses/register', [
            'name' => 'Test Shop',
            'owner_name' => 'Founder',
            'email' => 'founder@custospark.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'currency' => 'UGX',
        ])->assertCreated();

        $user = User::where('email', 'founder@custospark.com')->first();
        $this->assertTrue($user->hasRole('platform-admin'));
    }
}
