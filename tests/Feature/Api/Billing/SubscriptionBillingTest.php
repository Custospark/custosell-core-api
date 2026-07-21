<?php

namespace Tests\Feature\Api\Billing;

use App\Models\Business;
use App\Models\BillingPayment;
use App\Models\Plan;
use App\Models\Role;
use App\Models\Subscription;
use App\Models\User;
use Database\Seeders\PlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

class SubscriptionBillingTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected Business $business;

    protected string $token;

    protected Plan $essentialPlan;

    protected Plan $professionalPlan;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(PlanSeeder::class);

        $this->essentialPlan = Plan::where('slug', 'essential')->first();
        $this->professionalPlan = Plan::where('slug', 'professional')->first();

        $this->user = User::factory()->create(['is_active' => true]);
        $this->token = $this->user->createToken('test')->plainTextToken;

        $this->business = Business::factory()->create([
            'owner_id' => $this->user->id,
            'currency' => 'UGX',
            'status' => 'active',
        ]);
        $this->user->business_id = $this->business->id;
        $this->user->save();

        $adminRole = Role::create([
            'business_id' => $this->business->id,
            'name' => 'Admin',
            'slug' => 'admin',
            'permissions' => ['subscriptions' => true],
        ]);
        $this->user->role_id = $adminRole->id;
        $this->user->save();
    }

    protected function authHeaders(): array
    {
        return ['Authorization' => "Bearer {$this->token}"];
    }

    // ─── AUTH & AUTHORIZATION ───────────────────────────────────

    public function test_unauthenticated_user_cannot_access_subscriptions(): void
    {
        $this->getJson('/api/v1/subscriptions')->assertStatus(401);
        $this->getJson('/api/v1/subscriptions/current')->assertStatus(401);
        $this->postJson('/api/v1/subscriptions/subscribe', ['plan_id' => 1])->assertStatus(401);
        $this->getJson('/api/v1/subscriptions/access')->assertStatus(401);
    }

    public function test_authenticated_user_can_access_own_subscription(): void
    {
        $subscription = Subscription::create([
            'business_id' => $this->business->id,
            'plan_id' => $this->essentialPlan->id,
            'status' => 'active',
            'billing_cycle' => 'monthly',
            'starts_at' => now(),
            'next_billing_date' => now()->addMonth(),
        ]);

        $response = $this->withHeaders($this->authHeaders())
            ->getJson('/api/v1/subscriptions/current');

        $response->assertStatus(200)
            ->assertJsonStructure(['data' => ['id', 'business_id', 'plan_id', 'status']])
            ->assertJsonPath('data.id', $subscription->id);
    }

    // ─── PLANS ───────────────────────────────────────────────────

    public function test_list_all_plans_returns_all_with_correct_pricing(): void
    {
        $response = $this->getJson('/api/v1/plans');

        $response->assertStatus(200)
            ->assertJsonStructure(['data']);

        $names = collect($response->json('data'))->pluck('name')->toArray();
        $this->assertContains('Essential', $names);
        $this->assertContains('Professional', $names);
        $this->assertContains('Enterprise', $names);

        $essential = collect($response->json('data'))->firstWhere('slug', 'essential');
        $this->assertArrayHasKey('price_monthly', $essential);
        $this->assertEquals(75000, (int) $essential['price_monthly']);
    }

    public function test_list_active_plans_returns_only_active_sorted(): void
    {
        Plan::create([
            'name' => 'Inactive Plan',
            'slug' => 'inactive',
            'price_monthly' => 1000,
            'features' => [],
            'limits' => [],
            'is_active' => false,
            'sort_order' => 99,
        ]);

        $response = $this->getJson('/api/v1/plans/active');

        $response->assertStatus(200)
            ->assertJsonStructure(['data']);

        $plans = $response->json('data');
        foreach ($plans as $plan) {
            $this->assertTrue($plan['is_active'], "Plan {$plan['slug']} should be active");
        }

        $slugs = collect($plans)->pluck('slug')->toArray();
        $this->assertNotContains('inactive', $slugs);
    }

    public function test_get_single_plan(): void
    {
        $response = $this->getJson('/api/v1/plans/' . $this->essentialPlan->id);

        $response->assertStatus(200)
            ->assertJsonStructure(['data' => ['id', 'name', 'slug', 'price_monthly', 'features', 'limits']])
            ->assertJsonPath('data.name', 'Essential');
    }

    // ─── SUBSCRIPTION CREATION ───────────────────────────────────

    public function test_subscribe_creates_subscription_in_trial_status(): void
    {
        $response = $this->withHeaders($this->authHeaders())
            ->postJson('/api/v1/subscriptions/subscribe', [
                'plan_id' => $this->essentialPlan->id,
                'billing_cycle' => 'monthly',
            ]);

        $response->assertStatus(201)
            ->assertJsonStructure(['id', 'business_id', 'plan_id', 'status', 'trial_ends_at'])
            ->assertJsonPath('status', 'trial')
            ->assertJsonPath('business_id', $this->business->id)
            ->assertJsonPath('plan_id', $this->essentialPlan->id);
    }

    public function test_subscribe_creates_subscription_with_correct_plan_id(): void
    {
        $response = $this->withHeaders($this->authHeaders())
            ->postJson('/api/v1/subscriptions/subscribe', [
                'plan_id' => $this->professionalPlan->id,
                'billing_cycle' => 'monthly',
            ]);

        $response->assertStatus(201)
            ->assertJsonPath('plan_id', $this->professionalPlan->id);
    }

    public function test_subscribe_sets_trial_ends_at_correctly(): void
    {
        $this->essentialPlan->update(['trial_days' => 14]);

        $response = $this->withHeaders($this->authHeaders())
            ->postJson('/api/v1/subscriptions/subscribe', [
                'plan_id' => $this->essentialPlan->id,
                'billing_cycle' => 'monthly',
            ]);

        $response->assertStatus(201);

        $trialEndsAt = $response->json('trial_ends_at');
        $this->assertNotNull($trialEndsAt);

        $startsAt = $response->json('starts_at');
        $expectedEnd = now()->addDays(14)->startOfDay();
        $actualEnd = now()->parse($trialEndsAt)->startOfDay();
        $this->assertEquals($expectedEnd->toDateString(), $actualEnd->toDateString());
    }

    public function test_subscribe_with_yearly_billing_cycle(): void
    {
        $response = $this->withHeaders($this->authHeaders())
            ->postJson('/api/v1/subscriptions/subscribe', [
                'plan_id' => $this->essentialPlan->id,
                'billing_cycle' => 'yearly',
            ]);

        $response->assertStatus(201)
            ->assertJsonPath('billing_cycle', 'yearly');
    }

    // ─── SUBSCRIPTION QUERY ──────────────────────────────────────

    public function test_get_current_subscription_returns_subscription(): void
    {
        Subscription::create([
            'business_id' => $this->business->id,
            'plan_id' => $this->essentialPlan->id,
            'status' => 'active',
            'billing_cycle' => 'monthly',
            'starts_at' => now(),
            'next_billing_date' => now()->addMonth(),
        ]);

        $response = $this->withHeaders($this->authHeaders())
            ->getJson('/api/v1/subscriptions/current');

        $response->assertStatus(200)
            ->assertJsonStructure(['data' => ['id', 'business_id', 'plan_id', 'status']])
            ->assertJsonPath('data.business_id', $this->business->id);
    }

    public function test_get_current_subscription_returns_404_when_none_exists(): void
    {
        $response = $this->withHeaders($this->authHeaders())
            ->getJson('/api/v1/subscriptions/current');

        $response->assertStatus(404);
    }

    // ─── SUBSCRIPTION ACCESS ─────────────────────────────────────

    public function test_access_returns_true_when_subscription_is_active(): void
    {
        Subscription::create([
            'business_id' => $this->business->id,
            'plan_id' => $this->essentialPlan->id,
            'status' => 'active',
            'billing_cycle' => 'monthly',
            'starts_at' => now(),
            'next_billing_date' => now()->addMonth(),
        ]);

        $response = $this->withHeaders($this->authHeaders())
            ->getJson('/api/v1/subscriptions/access');

        $response->assertStatus(200)
            ->assertJsonPath('has_access', true);
    }

    public function test_access_returns_true_during_trial(): void
    {
        Subscription::create([
            'business_id' => $this->business->id,
            'plan_id' => $this->essentialPlan->id,
            'status' => 'trial',
            'billing_cycle' => 'monthly',
            'starts_at' => now()->subDays(5),
            'trial_ends_at' => now()->addDays(9),
        ]);

        $response = $this->withHeaders($this->authHeaders())
            ->getJson('/api/v1/subscriptions/access');

        $response->assertStatus(200)
            ->assertJsonPath('has_access', true);
    }

    public function test_access_returns_false_when_suspended(): void
    {
        Subscription::create([
            'business_id' => $this->business->id,
            'plan_id' => $this->essentialPlan->id,
            'status' => 'suspended',
            'billing_cycle' => 'monthly',
            'starts_at' => now()->subMonth(),
            'suspended_at' => now(),
        ]);

        $response = $this->withHeaders($this->authHeaders())
            ->getJson('/api/v1/subscriptions/access');

        $response->assertStatus(200)
            ->assertJsonPath('has_access', false);
    }

    public function test_access_returns_true_during_grace_period_when_past_due(): void
    {
        Subscription::create([
            'business_id' => $this->business->id,
            'plan_id' => $this->essentialPlan->id,
            'status' => 'past_due',
            'billing_cycle' => 'monthly',
            'starts_at' => now()->subMonth(),
            'grace_period_ends_at' => now()->addDays(3),
        ]);

        $response = $this->withHeaders($this->authHeaders())
            ->getJson('/api/v1/subscriptions/access');

        $response->assertStatus(200)
            ->assertJsonPath('has_access', true);
    }

    // ─── SUBSCRIPTION CANCELLATION ───────────────────────────────

    public function test_cancel_subscription_at_period_end(): void
    {
        $subscription = Subscription::create([
            'business_id' => $this->business->id,
            'plan_id' => $this->essentialPlan->id,
            'status' => 'active',
            'billing_cycle' => 'monthly',
            'starts_at' => now(),
            'next_billing_date' => now()->addMonth(),
        ]);

        $response = $this->withHeaders($this->authHeaders())
            ->postJson("/api/v1/subscriptions/{$subscription->id}/cancel");

        $response->assertStatus(200)
            ->assertJsonPath('message', 'Subscription will be cancelled at the end of the billing period.');
    }

    public function test_cancel_sets_cancel_at_period_end_flag(): void
    {
        $subscription = Subscription::create([
            'business_id' => $this->business->id,
            'plan_id' => $this->essentialPlan->id,
            'status' => 'active',
            'billing_cycle' => 'monthly',
            'starts_at' => now(),
            'next_billing_date' => now()->addMonth(),
        ]);

        $this->withHeaders($this->authHeaders())
            ->postJson("/api/v1/subscriptions/{$subscription->id}/cancel");

        $subscription->refresh();
        $this->assertTrue($subscription->isCancelAtPeriodEnd());
        $this->assertNull($subscription->cancelled_at);
    }

    // ─── PAYMENT INITIATION ──────────────────────────────────────

    public function test_initiate_payment_returns_422_for_missing_fields(): void
    {
        $subscription = Subscription::create([
            'business_id' => $this->business->id,
            'plan_id' => $this->essentialPlan->id,
            'status' => 'active',
            'billing_cycle' => 'monthly',
            'starts_at' => now(),
            'next_billing_date' => now()->addMonth(),
        ]);

        $response = $this->withHeaders($this->authHeaders())
            ->postJson('/api/v1/billing/payments/initiate', []);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['gateway_name', 'amount', 'currency', 'payment_type']);
    }

    public function test_initiate_payment_with_valid_data_creates_pending_payment(): void
    {
        Config::set('pesapal.enabled', true);

        $this->mock(\App\Services\Payment\Gateways\PesaPalGateway::class, function ($mock) {
            $mock->shouldReceive('isEnabled')->andReturn(true);
            $mock->shouldReceive('initiate')->andReturn([
                'gateway_txn_id' => 'mock-txn-123',
                'gateway_ref' => 'mock-ref-123',
                'type' => 'redirect',
                'redirect_url' => 'https://pay.pesapal.com/mock',
                'message' => 'Success',
                'raw_response' => [],
            ]);
        });

        $subscription = Subscription::create([
            'business_id' => $this->business->id,
            'plan_id' => $this->essentialPlan->id,
            'status' => 'active',
            'billing_cycle' => 'monthly',
            'starts_at' => now(),
            'next_billing_date' => now()->addMonth(),
        ]);

        $response = $this->withHeaders($this->authHeaders())
            ->postJson('/api/v1/billing/payments/initiate', [
                'gateway_name' => 'pesapal',
                'amount' => 75000,
                'currency' => 'UGX',
                'payment_type' => 'subscription',
            ]);

        $response->assertStatus(201);

        $this->assertDatabaseHas('billing_payments', [
            'business_id' => $this->business->id,
            'subscription_id' => $subscription->id,
            'amount' => 75000,
            'currency' => 'UGX',
            'status' => 'pending',
            'gateway_name' => 'pesapal',
        ]);
    }

    public function test_initiate_payment_returns_error_for_invalid_gateway(): void
    {
        Subscription::create([
            'business_id' => $this->business->id,
            'plan_id' => $this->essentialPlan->id,
            'status' => 'active',
            'billing_cycle' => 'monthly',
            'starts_at' => now(),
            'next_billing_date' => now()->addMonth(),
        ]);

        $response = $this->withHeaders($this->authHeaders())
            ->postJson('/api/v1/billing/payments/initiate', [
                'gateway_name' => 'nonexistent_gateway',
                'amount' => 75000,
                'currency' => 'UGX',
                'payment_type' => 'subscription',
            ]);

        $response->assertStatus(502)
            ->assertJsonStructure(['message']);
    }

    // ─── PAYMENT HISTORY ─────────────────────────────────────────

    public function test_payment_history_returns_payment_list(): void
    {
        $subscription = Subscription::create([
            'business_id' => $this->business->id,
            'plan_id' => $this->essentialPlan->id,
            'status' => 'active',
            'billing_cycle' => 'monthly',
            'starts_at' => now(),
            'next_billing_date' => now()->addMonth(),
        ]);

        BillingPayment::create([
            'business_id' => $this->business->id,
            'subscription_id' => $subscription->id,
            'amount' => 75000,
            'currency' => 'UGX',
            'method' => 'gateway',
            'payment_type' => 'subscription',
            'status' => 'completed',
            'gateway_name' => 'pesapal',
        ]);

        BillingPayment::create([
            'business_id' => $this->business->id,
            'subscription_id' => $subscription->id,
            'amount' => 75000,
            'currency' => 'UGX',
            'method' => 'gateway',
            'payment_type' => 'subscription',
            'status' => 'completed',
            'gateway_name' => 'pesapal',
        ]);

        $response = $this->withHeaders($this->authHeaders())
            ->getJson('/api/v1/billing/payments');

        $response->assertStatus(200)
            ->assertJsonStructure(['data'])
            ->assertJsonCount(2, 'data');
    }

    public function test_payment_history_returns_empty_array_when_no_payments(): void
    {
        $response = $this->withHeaders($this->authHeaders())
            ->getJson('/api/v1/billing/payments');

        $response->assertStatus(200)
            ->assertJsonStructure(['data'])
            ->assertJsonCount(0, 'data');
    }
}
