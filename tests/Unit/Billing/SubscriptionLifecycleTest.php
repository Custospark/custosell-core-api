<?php

namespace Tests\Unit\Billing;

use App\Enums\Billing\SubscriptionStatus;
use App\Models\Business;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Services\SubscriptionService;
use Database\Seeders\PlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SubscriptionLifecycleTest extends TestCase
{
    use RefreshDatabase;

    protected SubscriptionService $service;

    protected Business $business;

    protected Plan $trialPlan;

    protected Plan $noTrialPlan;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(PlanSeeder::class);

        $user = User::factory()->create(['is_active' => true]);
        $this->business = Business::factory()->create(['owner_id' => $user->id]);

        $this->trialPlan = Plan::where('slug', 'essential')->first();

        $this->noTrialPlan = Plan::create([
            'name' => 'No Trial',
            'slug' => 'no-trial',
            'description' => 'Plan without trial',
            'price_monthly' => 50000,
            'trial_days' => 0,
            'features' => ['sales' => true],
            'limits' => ['staff_users' => 1],
            'is_active' => true,
            'sort_order' => 99,
        ]);

        $this->service = app(SubscriptionService::class);
    }

    public function test_subscribe_creates_trial_subscription(): void
    {
        $subscription = $this->service->subscribe(
            $this->business->id,
            $this->trialPlan->id,
            'monthly'
        );

        $this->assertTrue($subscription->status === SubscriptionStatus::TRIAL);
        $this->assertNotNull($subscription->trial_ends_at);
        $this->assertEquals(
            $subscription->starts_at->copy()->addDays(14)->toDateString(),
            $subscription->trial_ends_at->toDateString()
        );
        $this->assertEquals($this->business->id, $subscription->business_id);
        $this->assertEquals($this->trialPlan->id, $subscription->plan_id);
    }

    public function test_subscribe_creates_past_due_when_no_trial(): void
    {
        $subscription = $this->service->subscribe(
            $this->business->id,
            $this->noTrialPlan->id,
            'monthly'
        );

        $this->assertTrue($subscription->status === SubscriptionStatus::PAST_DUE);
        $this->assertNull($subscription->trial_ends_at);
    }

    public function test_activate_subscription_sets_status_to_active(): void
    {
        $subscription = $this->service->subscribe(
            $this->business->id,
            $this->trialPlan->id,
            'monthly'
        );

        $activated = $this->service->activateSubscription($subscription);

        $this->assertTrue($activated->status === SubscriptionStatus::ACTIVE);
        $this->assertNotNull($activated->approved_at);
    }

    public function test_renew_subscription_extends_next_billing_date(): void
    {
        $subscription = Subscription::create([
            'business_id' => $this->business->id,
            'plan_id' => $this->trialPlan->id,
            'status' => SubscriptionStatus::ACTIVE,
            'billing_cycle' => 'monthly',
            'starts_at' => now()->subMonth(),
            'next_billing_date' => now()->subDay(),
        ]);

        $renewed = $this->service->renewSubscription($subscription);

        $this->assertTrue($renewed->status === SubscriptionStatus::ACTIVE);
        $this->assertTrue($renewed->next_billing_date->isFuture());
    }

    public function test_mark_past_due_sets_past_due_with_grace_period(): void
    {
        $subscription = Subscription::create([
            'business_id' => $this->business->id,
            'plan_id' => $this->trialPlan->id,
            'status' => SubscriptionStatus::ACTIVE,
            'billing_cycle' => 'monthly',
            'starts_at' => now()->subMonth(),
            'next_billing_date' => now()->subDay(),
        ]);

        $pastDue = $this->service->markPastDue($subscription);

        $this->assertTrue($pastDue->status === SubscriptionStatus::PAST_DUE);
        $this->assertNotNull($pastDue->grace_period_ends_at);
        $this->assertTrue($pastDue->grace_period_ends_at->isFuture());
    }

    public function test_mark_past_due_does_not_extend_existing_grace(): void
    {
        $subscription = Subscription::create([
            'business_id' => $this->business->id,
            'plan_id' => $this->trialPlan->id,
            'status' => SubscriptionStatus::ACTIVE,
            'billing_cycle' => 'monthly',
            'starts_at' => now()->subMonth(),
            'next_billing_date' => now()->subDay(),
        ]);

        $this->service->markPastDue($subscription);

        $this->expectException(\RuntimeException::class);

        $this->service->markPastDue($subscription->fresh());
    }

    public function test_suspend_sets_status_to_suspended(): void
    {
        $subscription = Subscription::create([
            'business_id' => $this->business->id,
            'plan_id' => $this->trialPlan->id,
            'status' => SubscriptionStatus::ACTIVE,
            'billing_cycle' => 'monthly',
            'starts_at' => now(),
            'next_billing_date' => now()->addMonth(),
        ]);

        $suspended = $this->service->suspend($subscription);

        $this->assertTrue($suspended->status === SubscriptionStatus::SUSPENDED);
        $this->assertNotNull($suspended->suspended_at);
    }

    public function test_cancel_with_immediate_true_ends_immediately(): void
    {
        $subscription = Subscription::create([
            'business_id' => $this->business->id,
            'plan_id' => $this->trialPlan->id,
            'status' => SubscriptionStatus::ACTIVE,
            'billing_cycle' => 'monthly',
            'starts_at' => now(),
            'next_billing_date' => now()->addMonth(),
        ]);

        $cancelled = $this->service->cancel($subscription->id, true);

        $this->assertTrue($cancelled->status === SubscriptionStatus::CANCELLED);
        $this->assertNotNull($cancelled->cancelled_at);
        $this->assertNotNull($cancelled->ends_at);
    }

    public function test_cancel_with_immediate_false_sets_cancel_at_period_end(): void
    {
        $subscription = Subscription::create([
            'business_id' => $this->business->id,
            'plan_id' => $this->trialPlan->id,
            'status' => SubscriptionStatus::ACTIVE,
            'billing_cycle' => 'monthly',
            'starts_at' => now(),
            'next_billing_date' => now()->addMonth(),
        ]);

        $cancelled = $this->service->cancel($subscription->id, false);

        $this->assertTrue($cancelled->status === SubscriptionStatus::ACTIVE);
        $this->assertTrue($cancelled->isCancelAtPeriodEnd());
        $this->assertNull($cancelled->cancelled_at);
    }

    public function test_has_access_returns_true_for_active(): void
    {
        Subscription::create([
            'business_id' => $this->business->id,
            'plan_id' => $this->trialPlan->id,
            'status' => SubscriptionStatus::ACTIVE->value,
            'billing_cycle' => 'monthly',
            'starts_at' => now(),
            'next_billing_date' => now()->addMonth(),
        ]);

        $this->assertTrue($this->service->hasAccess($this->business->id));
    }

    public function test_has_access_returns_true_for_trial_within_window(): void
    {
        Subscription::create([
            'business_id' => $this->business->id,
            'plan_id' => $this->trialPlan->id,
            'status' => SubscriptionStatus::TRIAL->value,
            'billing_cycle' => 'monthly',
            'starts_at' => now()->subDays(5),
            'trial_ends_at' => now()->addDays(9),
        ]);

        $this->assertTrue($this->service->hasAccess($this->business->id));
    }

    public function test_has_access_returns_true_for_past_due_within_grace(): void
    {
        Subscription::create([
            'business_id' => $this->business->id,
            'plan_id' => $this->trialPlan->id,
            'status' => SubscriptionStatus::PAST_DUE->value,
            'billing_cycle' => 'monthly',
            'starts_at' => now()->subMonth(),
            'grace_period_ends_at' => now()->addDays(3),
        ]);

        $this->assertTrue($this->service->hasAccess($this->business->id));
    }

    public function test_has_access_returns_false_for_suspended(): void
    {
        Subscription::create([
            'business_id' => $this->business->id,
            'plan_id' => $this->trialPlan->id,
            'status' => SubscriptionStatus::SUSPENDED->value,
            'billing_cycle' => 'monthly',
            'starts_at' => now()->subMonth(),
            'suspended_at' => now(),
        ]);

        $this->assertFalse($this->service->hasAccess($this->business->id));
    }

    public function test_has_access_returns_false_for_cancelled(): void
    {
        Subscription::create([
            'business_id' => $this->business->id,
            'plan_id' => $this->trialPlan->id,
            'status' => SubscriptionStatus::CANCELLED->value,
            'billing_cycle' => 'monthly',
            'starts_at' => now()->subMonth(),
            'cancelled_at' => now(),
        ]);

        $this->assertFalse($this->service->hasAccess($this->business->id));
    }

    public function test_has_access_returns_false_for_expired(): void
    {
        Subscription::create([
            'business_id' => $this->business->id,
            'plan_id' => $this->trialPlan->id,
            'status' => SubscriptionStatus::EXPIRED->value,
            'billing_cycle' => 'monthly',
            'starts_at' => now()->subYear(),
        ]);

        $this->assertFalse($this->service->hasAccess($this->business->id));
    }
}
