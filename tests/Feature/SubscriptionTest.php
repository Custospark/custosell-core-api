<?php

namespace Tests\Feature;

use App\Models\{Business, Plan, Subscription, User};
use Database\Seeders\PlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Subscription API contract after the payment-gating hardening: plan identity,
 * status and dates may ONLY change through the ownership-checked, payment-backed
 * flows (subscription payments, proration upgrades, billing-cycle payments) or
 * through the platform. The raw generic CRUD (create/update/delete by business
 * users) no longer exists, and the read-all registry is platform-only.
 */
class SubscriptionTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;
    protected Business $business;
    protected string $adminToken;
    protected Plan $plan;

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

        $this->plan = Plan::where('slug', 'essential')->first();
    }

    public function test_business_user_cannot_list_all_subscriptions(): void
    {
        Subscription::create([
            'business_id' => $this->business->id,
            'plan_id' => $this->plan->id,
            'status' => 'active',
            'starts_at' => now(),
        ]);

        $response = $this->withHeader('Authorization', "Bearer $this->adminToken")
            ->getJson('/api/v1/subscriptions');

        $response->assertStatus(403);
    }

    public function test_platform_admin_can_list_all_subscriptions(): void
    {
        $platformAdmin = User::factory()->create();
        $platformAdmin->assignRole('platform-admin');

        Subscription::create([
            'business_id' => $this->business->id,
            'plan_id' => $this->plan->id,
            'status' => 'active',
            'starts_at' => now(),
        ]);

        $otherBusiness = Business::factory()->create([
            'owner_id' => $this->admin->id,
            'currency' => 'UGX',
            'status' => 'active',
        ]);
        Subscription::create([
            'business_id' => $otherBusiness->id,
            'plan_id' => $this->plan->id,
            'status' => 'active',
            'starts_at' => now(),
        ]);

        $this->actingAs($platformAdmin, 'sanctum')
            ->getJson('/api/v1/subscriptions')
            ->assertOk()
            ->assertJsonStructure(['data']);
    }

    public function test_business_user_cannot_create_subscription_via_raw_crud(): void
    {
        $response = $this->withHeader('Authorization', "Bearer $this->adminToken")
            ->postJson('/api/v1/subscriptions', [
                'business_id' => $this->business->id,
                'plan_id' => $this->plan->id,
                'status' => 'active',
                'starts_at' => now()->toDateTimeString(),
            ]);

        $response->assertStatus(405);
        $this->assertDatabaseCount('subscriptions', 0);
    }

    public function test_business_user_cannot_update_subscription_via_raw_crud(): void
    {
        $subscription = Subscription::create([
            'business_id' => $this->business->id,
            'plan_id' => $this->plan->id,
            'status' => 'active',
            'starts_at' => now(),
        ]);

        $response = $this->withHeader('Authorization', "Bearer $this->adminToken")
            ->putJson("/api/v1/subscriptions/{$subscription->id}", [
                'business_id' => $this->business->id,
                'plan_id' => $this->plan->id,
                'status' => 'cancelled',
                'starts_at' => now()->toDateTimeString(),
            ]);

        $response->assertStatus(405);
        $this->assertSame('active', $subscription->fresh()->status->value);
    }

    public function test_business_user_cannot_reactivate_without_payment(): void
    {
        $subscription = Subscription::create([
            'business_id' => $this->business->id,
            'plan_id' => $this->plan->id,
            'status' => 'suspended',
            'starts_at' => now(),
        ]);

        $response = $this->withHeader('Authorization', "Bearer $this->adminToken")
            ->postJson("/api/v1/subscriptions/{$subscription->id}/reactivate");

        $response->assertStatus(404);
        $this->assertSame('suspended', $subscription->fresh()->status->value);
    }

    public function test_owner_can_view_own_current_subscription(): void
    {
        Subscription::create([
            'business_id' => $this->business->id,
            'plan_id' => $this->plan->id,
            'status' => 'active',
            'starts_at' => now(),
        ]);

        $response = $this->withHeader('Authorization', "Bearer $this->adminToken")
            ->getJson('/api/v1/subscriptions/current');

        $response->assertOk()
            ->assertJsonStructure(['data']);
    }

    public function test_cancel_subscription(): void
    {
        $subscription = Subscription::create([
            'business_id' => $this->business->id,
            'plan_id' => $this->plan->id,
            'status' => 'active',
            'starts_at' => now(),
        ]);

        $response = $this->withHeader('Authorization', "Bearer $this->adminToken")
            ->postJson("/api/v1/subscriptions/{$subscription->id}/cancel", [
                'immediate' => true,
            ]);

        $response->assertOk()
            ->assertJsonPath('message', 'Subscription has been cancelled immediately.');
    }
}