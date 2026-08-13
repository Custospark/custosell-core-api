<?php

namespace Tests\Unit\Billing;

use App\Enums\Billing\SubscriptionStatus;
use App\Models\Business;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Services\Billing\SubscriptionPaymentActionResolver;
use Database\Seeders\PlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SubscriptionPaymentActionResolverTest extends TestCase
{
    use RefreshDatabase;

    protected SubscriptionPaymentActionResolver $resolver;

    protected Plan $plan;

    protected Business $business;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(PlanSeeder::class);

        $user = User::factory()->create(['is_active' => true]);
        $this->business = Business::factory()->create(['owner_id' => $user->id]);
        $this->plan = Plan::where('slug', 'essential')->first();

        $this->resolver = app(SubscriptionPaymentActionResolver::class);
    }

    private function makeSubscription(array $overrides = []): Subscription
    {
        return Subscription::create(array_merge([
            'business_id' => $this->business->id,
            'plan_id' => $this->plan->id,
            'price_monthly_usd' => $this->plan->price_monthly_usd,
            'price_yearly_usd' => $this->plan->price_yearly_usd,
            'billing_cycle' => 'monthly',
            'status' => SubscriptionStatus::TRIAL,
            'onboarding_fee_paid' => true,
            'starts_at' => now(),
            'trial_ends_at' => now()->addDays(14),
        ], $overrides));
    }

    public function test_returns_subscribe_when_no_subscription(): void
    {
        $result = $this->resolver->resolve(null);

        $this->assertTrue($result['required']);
        $this->assertSame('subscribe', $result['intent']);
        $this->assertSame('Subscribe', $result['label']);
        $this->assertNotNull($result['message']);
    }

    public function test_returns_pay_onboarding_when_onboarding_fee_not_paid(): void
    {
        $subscription = $this->makeSubscription([
            'status' => SubscriptionStatus::TRIAL,
            'onboarding_fee_paid' => false,
            'onboarding_fee_usd' => 35,
        ]);

        $result = $this->resolver->resolve($subscription);

        $this->assertTrue($result['required']);
        $this->assertSame('pay_onboarding', $result['intent']);
        $this->assertSame('Pay Setup Fee', $result['label']);
        $this->assertNotNull($result['message']);
    }

    public function test_returns_no_onboarding_prompt_when_fee_is_zero(): void
    {
        $subscription = $this->makeSubscription([
            'status' => SubscriptionStatus::TRIAL,
            'onboarding_fee_paid' => false,
            'onboarding_fee_usd' => 0,
        ]);

        $result = $this->resolver->resolve($subscription);

        $this->assertFalse($result['required']);
        $this->assertNull($result['intent']);
        $this->assertNull($result['label']);
        $this->assertNull($result['message']);
    }

    public function test_returns_no_action_for_trial_with_onboarding_paid(): void
    {
        $subscription = $this->makeSubscription([
            'status' => SubscriptionStatus::TRIAL,
            'onboarding_fee_paid' => true,
        ]);

        $result = $this->resolver->resolve($subscription);

        $this->assertFalse($result['required']);
        $this->assertNull($result['intent']);
        $this->assertNull($result['label']);
        $this->assertNull($result['message']);
    }

    public function test_returns_no_action_for_active_subscription(): void
    {
        $subscription = $this->makeSubscription([
            'status' => SubscriptionStatus::ACTIVE,
        ]);

        $result = $this->resolver->resolve($subscription);

        $this->assertFalse($result['required']);
        $this->assertNull($result['intent']);
        $this->assertNull($result['label']);
        $this->assertNull($result['message']);
    }

    public function test_returns_renew_for_past_due(): void
    {
        $subscription = $this->makeSubscription([
            'status' => SubscriptionStatus::PAST_DUE,
        ]);

        $result = $this->resolver->resolve($subscription);

        $this->assertTrue($result['required']);
        $this->assertSame('renew', $result['intent']);
        $this->assertSame('Pay Outstanding', $result['label']);
        $this->assertNotNull($result['message']);
    }

    public function test_returns_reactivate_for_suspended(): void
    {
        $subscription = $this->makeSubscription([
            'status' => SubscriptionStatus::SUSPENDED,
        ]);

        $result = $this->resolver->resolve($subscription);

        $this->assertTrue($result['required']);
        $this->assertSame('reactivate', $result['intent']);
        $this->assertSame('Reactivate', $result['label']);
        $this->assertNotNull($result['message']);
    }

    public function test_returns_resubscribe_for_expired(): void
    {
        $subscription = $this->makeSubscription([
            'status' => SubscriptionStatus::EXPIRED,
        ]);

        $result = $this->resolver->resolve($subscription);

        $this->assertTrue($result['required']);
        $this->assertSame('resubscribe', $result['intent']);
        $this->assertSame('Re-subscribe', $result['label']);
        $this->assertNotNull($result['message']);
    }

    public function test_returns_subscribe_for_cancelled(): void
    {
        $subscription = $this->makeSubscription([
            'status' => SubscriptionStatus::CANCELLED,
        ]);

        $result = $this->resolver->resolve($subscription);

        $this->assertTrue($result['required']);
        $this->assertSame('subscribe', $result['intent']);
        $this->assertSame('Subscribe', $result['label']);
        $this->assertNotNull($result['message']);
    }

    public function test_onboarding_check_takes_priority_over_status(): void
    {
        $subscription = $this->makeSubscription([
            'status' => SubscriptionStatus::ACTIVE,
            'onboarding_fee_paid' => false,
            'onboarding_fee_usd' => 35,
        ]);

        $result = $this->resolver->resolve($subscription);

        $this->assertTrue($result['required']);
        $this->assertSame('pay_onboarding', $result['intent']);
    }
}
