<?php

namespace Tests\Unit\Billing;

use App\Enums\Billing\PaymentType;
use App\Enums\Billing\SubscriptionStatus;
use App\Models\BillingPayment;
use App\Models\Business;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Services\SubscriptionService;
use App\Services\Billing\PaymentService;
use App\Services\Payment\GatewayService;
use Database\Seeders\PlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ForensicGapFixTest extends TestCase
{
    use RefreshDatabase;

    protected SubscriptionService $subscriptionService;
    protected PaymentService $paymentService;
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
            'price_monthly_usd' => 15,
            'trial_days' => 0,
            'features' => ['sales' => true],
            'limits' => ['staff_users' => 1],
            'is_active' => true,
            'sort_order' => 99,
        ]);

        $this->subscriptionService = app(SubscriptionService::class);
        $this->paymentService = app(PaymentService::class);
    }

    // ════════════════════════════════════════════════════════════════════
    //  C1 — activateAfterOnboarding accepts TRIAL status
    // ════════════════════════════════════════════════════════════════════

    public function test_c1_activate_after_onboarding_with_trial_and_future_trial_ends_at_keeps_trial(): void
    {
        $subscription = $this->subscriptionService->subscribe(
            $this->business->id,
            $this->trialPlan->id,
            'monthly',
        );

        $this->assertSame(SubscriptionStatus::TRIAL, $subscription->status);
        $this->assertTrue($subscription->trial_ends_at->isFuture());

        $activated = $this->subscriptionService->activateAfterOnboarding($subscription);

        $this->assertSame(SubscriptionStatus::TRIAL, $activated->status);
        $this->assertTrue($activated->onboarding_fee_paid);
        $this->assertTrue($activated->trial_ends_at->isFuture());
        $this->assertFalse($activated->trial_used);
    }

    public function test_c1_activate_after_onboarding_with_trial_and_past_trial_ends_at_starts_new_trial(): void
    {
        $subscription = $this->subscriptionService->subscribe(
            $this->business->id,
            $this->trialPlan->id,
            'monthly',
        );

        // Force trial into the past
        $subscription->update(['trial_ends_at' => now()->subDay()]);

        $activated = $this->subscriptionService->activateAfterOnboarding($subscription->fresh());

        $this->assertSame(SubscriptionStatus::TRIAL, $activated->status);
        $this->assertTrue($activated->onboarding_fee_paid);
        $this->assertTrue($activated->trial_used);
        $this->assertTrue($activated->trial_ends_at->isFuture());
    }

    public function test_c1_activate_after_onboarding_with_past_due_works(): void
    {
        $subscription = $this->subscriptionService->subscribe(
            $this->business->id,
            $this->noTrialPlan->id,
            'monthly',
        );

        $this->assertSame(SubscriptionStatus::PAST_DUE, $subscription->status);

        $activated = $this->subscriptionService->activateAfterOnboarding($subscription);

        $this->assertSame(SubscriptionStatus::ACTIVE, $activated->status);
        $this->assertTrue($activated->onboarding_fee_paid);
    }

    public function test_c1_activate_after_onboarding_rejects_active_status(): void
    {
        $subscription = $this->subscriptionService->subscribe(
            $this->business->id,
            $this->noTrialPlan->id,
            'monthly',
        );

        $activated = $this->subscriptionService->activateAfterOnboarding($subscription);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Cannot activate after onboarding');

        $this->subscriptionService->activateAfterOnboarding($activated);
    }

    // ════════════════════════════════════════════════════════════════════
    //  C2 — trial_used not set on subscribe, only when trial actually starts
    // ════════════════════════════════════════════════════════════════════

    public function test_c2_subscribe_with_trial_does_not_set_trial_used(): void
    {
        $subscription = $this->subscriptionService->subscribe(
            $this->business->id,
            $this->trialPlan->id,
            'monthly',
        );

        $this->assertFalse($subscription->trial_used);
    }

    public function test_c2_activate_after_onboarding_sets_trial_used_when_entering_trial(): void
    {
        $subscription = $this->subscriptionService->subscribe(
            $this->business->id,
            $this->trialPlan->id,
            'monthly',
        );

        // Force trial into the past so activateAfterOnboarding starts a new one
        $subscription->update(['trial_ends_at' => now()->subDay()]);

        $activated = $this->subscriptionService->activateAfterOnboarding($subscription->fresh());

        $this->assertTrue($activated->trial_used);
    }

    public function test_c2_activate_after_onboarding_without_trial_does_not_set_trial_used(): void
    {
        $subscription = $this->subscriptionService->subscribe(
            $this->business->id,
            $this->noTrialPlan->id,
            'monthly',
        );

        $activated = $this->subscriptionService->activateAfterOnboarding($subscription);

        $this->assertSame(SubscriptionStatus::ACTIVE, $activated->status);
        $this->assertFalse($activated->trial_used);
    }

    // ════════════════════════════════════════════════════════════════════
    //  H2 — Trial expired → PAST_DUE (not EXPIRED)
    // ════════════════════════════════════════════════════════════════════

    public function test_h2_trial_expired_transitions_to_past_due(): void
    {
        $subscription = $this->subscriptionService->subscribe(
            $this->business->id,
            $this->trialPlan->id,
            'monthly',
        );

        $subscription->update(['trial_ends_at' => now()->subDay()]);
        $this->subscriptionService->processExpiredTrials();
        $subscription->refresh();

        $this->assertSame(SubscriptionStatus::PAST_DUE, $subscription->status);
        $this->assertNotNull($subscription->grace_period_ends_at);
    }

    public function test_h2_trial_expired_still_has_access_during_grace(): void
    {
        $subscription = $this->subscriptionService->subscribe(
            $this->business->id,
            $this->trialPlan->id,
            'monthly',
        );

        $subscription->update(['trial_ends_at' => now()->subDay()]);
        $this->subscriptionService->processExpiredTrials();

        // PAST_DUE with active grace → has access
        $this->assertTrue($this->subscriptionService->hasAccess($this->business->id));
    }

    public function test_h2_no_subscription_goes_to_expired_from_trial_via_process_due_transitions(): void
    {
        $subscription = $this->subscriptionService->subscribe(
            $this->business->id,
            $this->trialPlan->id,
            'monthly',
        );

        $subscription->update(['trial_ends_at' => now()->subDay()]);

        // hasAccess triggers processDueTransitions internally
        $this->assertTrue($this->subscriptionService->hasAccess($this->business->id));

        $subscription->refresh();
        $this->assertNotSame(SubscriptionStatus::EXPIRED, $subscription->status);
        $this->assertSame(SubscriptionStatus::PAST_DUE, $subscription->status);
    }

    // ════════════════════════════════════════════════════════════════════
    //  H3 — grace_used + past billing → SUSPENDED directly
    // ════════════════════════════════════════════════════════════════════

    public function test_h3_grace_used_subscription_goes_to_suspended_when_billing_past(): void
    {
        $subscription = Subscription::create([
            'business_id' => $this->business->id,
            'plan_id' => $this->trialPlan->id,
            'status' => SubscriptionStatus::ACTIVE,
            'billing_cycle' => 'monthly',
            'starts_at' => now()->subMonths(2),
            'next_billing_date' => now()->subDay(),
            'grace_used' => true,
        ]);

        // hasAccess triggers processDueTransitions
        $this->assertFalse($this->subscriptionService->hasAccess($this->business->id));

        $subscription->refresh();
        $this->assertSame(SubscriptionStatus::SUSPENDED, $subscription->status);
    }

    public function test_h3_grace_not_used_still_goes_to_past_due(): void
    {
        $subscription = Subscription::create([
            'business_id' => $this->business->id,
            'plan_id' => $this->trialPlan->id,
            'status' => SubscriptionStatus::ACTIVE,
            'billing_cycle' => 'monthly',
            'starts_at' => now()->subMonths(2),
            'next_billing_date' => now()->subDay(),
            'grace_used' => false,
        ]);

        // hasAccess triggers processDueTransitions
        $this->assertTrue($this->subscriptionService->hasAccess($this->business->id));

        $subscription->refresh();
        $this->assertSame(SubscriptionStatus::PAST_DUE, $subscription->status);
    }

    // ════════════════════════════════════════════════════════════════════
    //  H5 — Idempotency key prevents duplicate payments
    // ════════════════════════════════════════════════════════════════════

    public function test_h5_idempotency_key_returns_existing_payment(): void
    {
        $subscription = $this->subscriptionService->subscribe(
            $this->business->id,
            $this->trialPlan->id,
            'monthly',
        );

        // Create first pending payment with idempotency key
        $payment = $this->paymentService->createPending([
            'business_id' => $this->business->id,
            'subscription_id' => $subscription->id,
            'amount' => 50000,
            'currency' => 'UGX',
            'method' => 'gateway',
            'payment_type' => 'onboarding',
            'gateway_name' => 'pesapal',
            'idempotency_key' => 'test-key-abc-123',
        ]);

        $this->assertTrue($payment->isPending());

        // Try to find by idempotency key via repo
        $repo = app(\App\Repositories\Contracts\PaymentRepositoryInterface::class);
        $found = $repo->findByIdempotencyKey('test-key-abc-123');

        $this->assertNotNull($found);
        $this->assertSame($payment->id, $found->id);

        // Different key should not match
        $notFound = $repo->findByIdempotencyKey('different-key');
        $this->assertNull($notFound);
    }

    // ════════════════════════════════════════════════════════════════════
    //  H1 — changePlan method on SubscriptionService
    // ════════════════════════════════════════════════════════════════════

    public function test_h1_change_plan_updates_subscription_plan(): void
    {
        $subscription = $this->subscriptionService->subscribe(
            $this->business->id,
            $this->trialPlan->id,
            'monthly',
        );

        $changed = $this->subscriptionService->changePlan(
            $subscription->fresh(),
            $this->noTrialPlan->id
        );

        $this->assertSame($this->noTrialPlan->id, $changed->plan_id);
        $this->assertSame((float) $this->noTrialPlan->price_monthly_usd, (float) $changed->price_monthly_usd);
    }

    public function test_h1_change_plan_with_invalid_plan_throws(): void
    {
        $subscription = $this->subscriptionService->subscribe(
            $this->business->id,
            $this->trialPlan->id,
            'monthly',
        );

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Plan not found');

        $this->subscriptionService->changePlan($subscription->fresh(), 99999);
    }

    // ════════════════════════════════════════════════════════════════════
    //  M2 — getByBusiness uses fresh(), not a second DB query
    // ════════════════════════════════════════════════════════════════════

    public function test_m2_get_by_business_returns_updated_subscription(): void
    {
        $this->subscriptionService->subscribe(
            $this->business->id,
            $this->trialPlan->id,
            'monthly',
        );

        // Force trial into the past
        $subscription = $this->subscriptionService->getByBusiness($this->business->id);
        $subscription->update(['trial_ends_at' => now()->subDay()]);

        // getByBusiness should run processDueTransitions and return the updated subscription
        // Since it used fresh(), the subscription should reflect the transition
        $fresh = $this->subscriptionService->getByBusiness($this->business->id);

        // Trial expired → PAST_DUE
        $this->assertSame(SubscriptionStatus::PAST_DUE, $fresh->status);
    }

    // ════════════════════════════════════════════════════════════════════
    //  H4 — Payment metadata propagation
    // ════════════════════════════════════════════════════════════════════

    public function test_h4_payment_metadata_stored_correctly(): void
    {
        $subscription = $this->subscriptionService->subscribe(
            $this->business->id,
            $this->trialPlan->id,
            'monthly',
        );

        $metadata = ['action' => 'upgrade', 'to_plan_id' => 99];

        $payment = $this->paymentService->createPending([
            'business_id' => $this->business->id,
            'subscription_id' => $subscription->id,
            'amount' => 10000,
            'currency' => 'UGX',
            'method' => 'gateway',
            'payment_type' => PaymentType::UPGRADE_PRORATION->value,
            'gateway_name' => 'pesapal',
            'metadata' => $metadata,
        ]);

        $this->assertNotNull($payment->metadata);
        $this->assertSame('upgrade', $payment->metadata['action']);
        $this->assertSame(99, $payment->metadata['to_plan_id']);
    }

    // ════════════════════════════════════════════════════════════════════
    //  EXPIRED acceptance — activateSubscription and activateAfterOnboarding
    // ════════════════════════════════════════════════════════════════════

    public function test_activate_subscription_accepts_expired_status(): void
    {
        $subscription = Subscription::create([
            'business_id' => $this->business->id,
            'plan_id' => $this->trialPlan->id,
            'status' => SubscriptionStatus::EXPIRED,
            'billing_cycle' => 'monthly',
            'starts_at' => now()->subMonths(2),
            'ends_at' => now()->subMonth(),
        ]);

        $activated = $this->subscriptionService->activateSubscription($subscription);

        $this->assertSame(SubscriptionStatus::ACTIVE, $activated->status);
    }

    public function test_activate_after_onboarding_accepts_expired_status(): void
    {
        $subscription = Subscription::create([
            'business_id' => $this->business->id,
            'plan_id' => $this->noTrialPlan->id,
            'status' => SubscriptionStatus::EXPIRED,
            'billing_cycle' => 'monthly',
            'starts_at' => now()->subMonths(2),
            'ends_at' => now()->subMonth(),
            'onboarding_fee_paid' => false,
        ]);

        $activated = $this->subscriptionService->activateAfterOnboarding($subscription);

        $this->assertSame(SubscriptionStatus::ACTIVE, $activated->status);
        $this->assertTrue($activated->onboarding_fee_paid);
    }
}
