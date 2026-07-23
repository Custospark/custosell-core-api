<?php

namespace Tests\Feature;

use App\Models\PlatformNotificationDispatch;
use App\Models\User;
use Database\Seeders\PlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PlatformTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->artisan('migrate', ['--force' => true]);
        $this->seed(PlanSeeder::class);
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

    public function test_platform_admin_notify_users_creates_dispatch_log(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('platform-admin');

        $target = User::factory()->create(['business_id' => null]);

        $this->actingAs($admin, 'sanctum')
            ->postJson('/api/v1/platform/users/notify', [
                'user_ids' => [$target->id],
                'intention' => 'announcement',
                'message' => 'Please review the updated billing policy.',
                'subject' => 'Billing update',
                'channel' => 'in_app',
            ])
            ->assertOk();

        $this->assertDatabaseHas('platform_notification_dispatches', [
            'actor_id' => $admin->id,
            'dispatch_type' => 'message',
            'target_kind' => 'user',
            'intention' => 'announcement',
            'subject' => 'Billing update',
            'recipient_count' => 1,
        ]);

        $this->actingAs($admin, 'sanctum')
            ->getJson('/api/v1/platform/notification-dispatches')
            ->assertOk()
            ->assertJsonPath('data.0.message_preview', 'Please review the updated billing policy.')
            ->assertJsonPath('data.0.actor.id', $admin->id);

        $dispatch = PlatformNotificationDispatch::first();
        $this->assertNotNull($dispatch);

        $this->actingAs($admin, 'sanctum')
            ->getJson('/api/v1/platform/notification-dispatches/'.$dispatch->id)
            ->assertOk()
            ->assertJsonPath('data.message', 'Please review the updated billing policy.')
            ->assertJsonPath('data.recipients.0.id', $target->id);

        $this->actingAs($admin, 'sanctum')
            ->deleteJson('/api/v1/platform/notification-dispatches/'.$dispatch->id)
            ->assertOk()
            ->assertJsonPath('message', 'Sent message removed from log.');

        $this->assertDatabaseMissing('platform_notification_dispatches', ['id' => $dispatch->id]);
    }

    public function test_platform_admin_can_bulk_delete_dispatch_log(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('platform-admin');

        $ids = [];
        foreach (range(1, 2) as $_) {
            $target = User::factory()->create(['business_id' => null]);
            $this->actingAs($admin, 'sanctum')
                ->postJson('/api/v1/platform/users/notify', [
                    'user_ids' => [$target->id],
                    'intention' => 'custom',
                    'message' => 'Bulk delete test message.',
                    'channel' => 'in_app',
                ])
                ->assertOk();
            $ids[] = PlatformNotificationDispatch::query()->latest('id')->value('id');
        }

        $this->actingAs($admin, 'sanctum')
            ->postJson('/api/v1/platform/notification-dispatches/bulk-delete', ['ids' => $ids])
            ->assertOk()
            ->assertJsonPath('deleted', 2);

        foreach ($ids as $id) {
            $this->assertDatabaseMissing('platform_notification_dispatches', ['id' => $id]);
        }
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
            'plan_id' => 1,
            'privacy_consent' => true,
        ])->assertCreated();

        $user = User::where('email', 'founder@custospark.com')->first();
        $this->assertTrue($user->hasRole('platform-admin'));
    }
}
