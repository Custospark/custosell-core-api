<?php

namespace Tests\Unit\Billing;

use App\Enums\Billing\PaymentType;
use App\Enums\Billing\ScheduledChangeType;
use App\Enums\Billing\SubscriptionStatus;
use App\Models\Business;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\SubscriptionScheduledChange;
use App\Models\User;
use App\Services\Billing\PaymentService;
use App\Services\Billing\PaymentQuoteService;
use App\Services\Billing\SubscriptionProrationCalculator;
use App\Services\Billing\SubscriptionScheduledChangeService;
use App\Services\Payment\GatewayService;
use App\Services\SubscriptionService;
use Database\Seeders\PlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Billing lifecycle tests with real user stories, real names, and actual Plan prices.
 *
 * Coverage:
 *   User Story 1  — Grace Hopper: register, subscribe to Essential (trial), pay onboarding, activate
 *   User Story 2  — Alan Turing: subscribe to Professional (trial), let trial expire
 *   User Story 3  — Margaret Hamilton: subscribe to Essential (no trial), pay onboarding, activate
 *   User Story 4  — Tim Berners-Lee: subscribe to Enterprise, upgrade to Enterprise
 *   User Story 5  — Ada Lovelace: subscribe to Essential, schedule downgrade
 *   User Story 6  — Linus Torvalds: subscribe to Essential, cancel at period end
 *   User Story 7  — Dennis Ritchie: subscribe to Essential, cancel immediately
 *   User Story 8  — Grace Hopper: use referral code during subscription
 *   User Story 9  — Access checks for every subscription status
 *   User Story 10 — Past due → grace → suspend → reactivate
 *   User Story 11 — Renewal processing
 *   User Story 12 — Cancel-at-period-end cron processing
 *   User Story 13 — Duplicate subscription prevention
 *   User Story 14 — Invalid state transitions
 *   User Story 15 — Payment lifecycle (pending → complete / fail / refund)
 *   User Story 16 — Scheduled change: apply pending changes (cron)
 */
class BillingLifecycleTest extends TestCase
{
    use RefreshDatabase;

    protected SubscriptionService $subscriptionService;
    protected PaymentService $paymentService;
    protected GatewayService $gatewayService;
    protected SubscriptionScheduledChangeService $scheduledChangeService;
    protected PaymentQuoteService $paymentQuoteService;
    protected SubscriptionProrationCalculator $prorationCalculator;

    protected Plan $essential;
    protected Plan $professional;
    protected Plan $enterprise;
    protected Plan $noTrial;

    // ─── User Story 1: Grace Hopper ─────────────────────────────────────
    protected User $grace;
    protected Business $aceHardware;

    // ─── User Story 2: Alan Turing ────────────────────────────────────────
    protected User $alan;
    protected Business $enigmaTech;

    // ─── User Story 3: Margaret Hamilton ──────────────────────────────────
    protected User $margaret;
    protected Business $apolloSoft;

    // ─── User Story 4: Tim Berners-Lee ────────────────────────────────────
    protected User $tim;
    protected Business $webFoundation;

    // ─── User Story 5: Ada Lovelace ───────────────────────────────────────
    protected User $ada;
    protected Business $analyticalEngine;

    // ─── User Story 6: Linus Torvalds ─────────────────────────────────────
    protected User $linus;
    protected Business $linuxFdn;

    // ─── User Story 7: Dennis Ritchie ─────────────────────────────────────
    protected User $dennis;
    protected Business $bellLabs;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PlanSeeder::class);

        $this->essential = Plan::where('slug', 'essential')->first();
        $this->professional = Plan::where('slug', 'professional')->first();
        $this->enterprise = Plan::where('slug', 'enterprise')->first();

        $this->noTrial = Plan::create([
            'name' => 'Starter',
            'slug' => 'starter',
            'description' => 'No-trial plan',
            'price_monthly' => 30000,
            'price_yearly' => 300000,
            'onboarding_fee_ugx' => 50000,
            'trial_days' => 0,
            'billing_cycle' => 'both',
            'features' => ['sales' => true],
            'limits' => ['max_staff' => 1, 'max_products' => 100],
            'is_active' => true,
            'sort_order' => 99,
        ]);

        $this->subscriptionService = app(SubscriptionService::class);
        $this->paymentService = app(PaymentService::class);
        $this->gatewayService = app(GatewayService::class);
        $this->scheduledChangeService = app(SubscriptionScheduledChangeService::class);
        $this->paymentQuoteService = app(PaymentQuoteService::class);
        $this->prorationCalculator = app(SubscriptionProrationCalculator::class);

        // Grace Hopper — Ace Hardware Kikuubo
        $this->grace = User::factory()->create(['name' => 'Grace Hopper', 'email' => 'grace@acehardware.com', 'is_active' => true]);
        $this->aceHardware = Business::factory()->create(['owner_id' => $this->grace->id, 'name' => 'Ace Hardware Kikuubo', 'currency' => 'UGX']);

        // Alan Turing — Enigma Tech Solutions
        $this->alan = User::factory()->create(['name' => 'Alan Turing', 'email' => 'alan@enigmatech.com', 'is_active' => true]);
        $this->enigmaTech = Business::factory()->create(['owner_id' => $this->alan->id, 'name' => 'Enigma Tech Solutions', 'currency' => 'UGX']);

        // Margaret Hamilton — Apollo Software Ltd
        $this->margaret = User::factory()->create(['name' => 'Margaret Hamilton', 'email' => 'margaret@apollosoft.com', 'is_active' => true]);
        $this->apolloSoft = Business::factory()->create(['owner_id' => $this->margaret->id, 'name' => 'Apollo Software Ltd', 'currency' => 'UGX']);

        // Tim Berners-Lee — Web Foundation
        $this->tim = User::factory()->create(['name' => 'Tim Berners-Lee', 'email' => 'tim@webfoundation.org', 'is_active' => true]);
        $this->webFoundation = Business::factory()->create(['owner_id' => $this->tim->id, 'name' => 'Web Foundation', 'currency' => 'UGX']);

        // Ada Lovelace — Analytical Engine Co
        $this->ada = User::factory()->create(['name' => 'Ada Lovelace', 'email' => 'ada@analyticalengine.com', 'is_active' => true]);
        $this->analyticalEngine = Business::factory()->create(['owner_id' => $this->ada->id, 'name' => 'Analytical Engine Co', 'currency' => 'UGX']);

        // Linus Torvalds — Linux Foundation
        $this->linus = User::factory()->create(['name' => 'Linus Torvalds', 'email' => 'linus@linuxfoundation.org', 'is_active' => true]);
        $this->linuxFdn = Business::factory()->create(['owner_id' => $this->linus->id, 'name' => 'Linux Foundation', 'currency' => 'UGX']);

        // Dennis Ritchie — Bell Labs Computing
        $this->dennis = User::factory()->create(['name' => 'Dennis Ritchie', 'email' => 'dennis@bell-labs.com', 'is_active' => true]);
        $this->bellLabs = Business::factory()->create(['owner_id' => $this->dennis->id, 'name' => 'Bell Labs Computing', 'currency' => 'UGX']);
    }

    // ════════════════════════════════════════════════════════════════════
    //  USER STORY 1 — Grace Hopper: Trial → Pay onboarding → Active
    // ════════════════════════════════════════════════════════════════════

    public function test_grace_hopper_registers_and_subscribes_to_essential_trial(): void
    {
        $subscription = $this->subscriptionService->subscribe(
            $this->aceHardware->id,
            $this->essential->id,
            'monthly',
        );

        $this->assertSame(SubscriptionStatus::TRIAL, $subscription->status);
        $this->assertSame($this->aceHardware->id, $subscription->business_id);
        $this->assertSame($this->essential->id, $subscription->plan_id);
        $this->assertSame('monthly', $subscription->billing_cycle);
        $this->assertNotNull($subscription->trial_ends_at);
        $this->assertTrue($subscription->trial_ends_at->isFuture());
        $this->assertSame(75_000, (int) $subscription->price_monthly);
        $this->assertSame(150_000, (int) $subscription->onboarding_fee_ugx);
        $this->assertSame(750_000, (int) $subscription->price_yearly);
        $this->assertFalse($subscription->onboarding_fee_paid);
    }

    public function test_grace_hopper_pays_onboarding_and_activates_subscription(): void
    {
        $subscription = $this->subscriptionService->subscribe(
            $this->aceHardware->id,
            $this->essential->id,
            'monthly',
        );

        $this->assertSame(SubscriptionStatus::TRIAL, $subscription->status);

        // Grace pays the UGX 150,000 onboarding fee
        $payment = $this->paymentService->createPending([
            'business_id' => $this->aceHardware->id,
            'subscription_id' => $subscription->id,
            'amount' => 150_000,
            'currency' => 'UGX',
            'method' => 'gateway',
            'payment_type' => 'subscription',
            'gateway_name' => 'pesapal',
        ]);

        $this->assertTrue($payment->isPending());
        $this->assertSame(150_000, (int) $payment->amount);
        $this->assertSame('UGX', $payment->currency);

        // Payment completes → activateSubscription (trial → active)
        $completed = $this->paymentService->complete($payment, 'bypass');
        $this->assertTrue($completed->isCompleted());

        $activated = $this->subscriptionService->activateSubscription($subscription->fresh());

        $this->assertSame(SubscriptionStatus::ACTIVE, $activated->status);
        $this->assertNotNull($activated->approved_at);
        $this->assertTrue($activated->next_billing_date->isFuture());
    }

    public function test_grace_hopper_renews_subscription(): void
    {
        $subscription = $this->subscribeAndActivateEssential($this->aceHardware);

        // Simulate period end by moving next_billing_date to the past
        $subscription->update(['next_billing_date' => Carbon::now()->subDay()]);

        $renewed = $this->subscriptionService->renewSubscription($subscription->fresh());

        $this->assertSame(SubscriptionStatus::ACTIVE, $renewed->status);
        $this->assertTrue($renewed->next_billing_date->isFuture());
        $this->assertNull($renewed->grace_period_ends_at);
    }

    public function test_grace_hopper_renewal_fails_when_not_active(): void
    {
        $subscription = $this->subscriptionService->subscribe(
            $this->aceHardware->id,
            $this->essential->id,
            'monthly',
        );

        $this->assertSame(SubscriptionStatus::TRIAL, $subscription->status);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Cannot renew subscription');
        $this->subscriptionService->renewSubscription($subscription);
    }

    // ════════════════════════════════════════════════════════════════════
    //  USER STORY 2 — Alan Turing: Subscribe to Professional, let trial expire
    // ════════════════════════════════════════════════════════════════════

    public function test_alan_turing_subscribes_to_professional_trial(): void
    {
        $subscription = $this->subscriptionService->subscribe(
            $this->enigmaTech->id,
            $this->professional->id,
            'monthly',
        );

        $this->assertSame(SubscriptionStatus::TRIAL, $subscription->status);
        $this->assertSame($this->professional->id, $subscription->plan_id);
        $this->assertSame(200_000, (int) $subscription->price_monthly);
        $this->assertSame(350_000, (int) $subscription->onboarding_fee_ugx);
    }

    public function test_alan_turing_trial_expires_to_past_due_with_grace(): void
    {
        $subscription = $this->subscriptionService->subscribe(
            $this->enigmaTech->id,
            $this->professional->id,
            'monthly',
        );

        // Force trial into the past
        $subscription->update(['trial_ends_at' => Carbon::now()->subDay()]);

        $expired = $this->subscriptionService->processExpiredTrials();

        $this->assertSame(1, $expired);

        $subscription->refresh();
        $this->assertSame(SubscriptionStatus::PAST_DUE, $subscription->status);
        $this->assertNotNull($subscription->grace_period_ends_at);
        $this->assertTrue($subscription->grace_period_ends_at->isFuture());
        $this->assertTrue($subscription->grace_used);
    }

    public function test_alan_turing_still_has_access_during_trial_grace_period(): void
    {
        $subscription = $this->subscriptionService->subscribe(
            $this->enigmaTech->id,
            $this->professional->id,
            'monthly',
        );

        $subscription->update(['trial_ends_at' => Carbon::now()->subDay()]);
        $this->subscriptionService->processExpiredTrials();
        $subscription->refresh();

        // Access is still granted during the 7-day grace period
        $this->assertTrue($this->subscriptionService->hasAccess($this->enigmaTech->id));
    }

    // ════════════════════════════════════════════════════════════════════
    //  USER STORY 3 — Margaret Hamilton: No-trial plan → Pay onboarding → Active
    // ════════════════════════════════════════════════════════════════════

    public function test_margaret_hamilton_subscribes_without_trial(): void
    {
        $subscription = $this->subscriptionService->subscribe(
            $this->apolloSoft->id,
            $this->noTrial->id,
            'monthly',
        );

        $this->assertSame(SubscriptionStatus::PAST_DUE, $subscription->status);
        $this->assertNull($subscription->trial_ends_at);
        $this->assertSame(30_000, (int) $subscription->price_monthly);
        $this->assertSame(50_000, (int) $subscription->onboarding_fee_ugx);
    }

    public function test_margaret_hamilton_pays_onboarding_and_activates_no_trial_plan(): void
    {
        $subscription = $this->subscriptionService->subscribe(
            $this->apolloSoft->id,
            $this->noTrial->id,
            'monthly',
        );

        $this->assertSame(SubscriptionStatus::PAST_DUE, $subscription->status);

        // Pay UGX 50,000 onboarding
        $payment = $this->paymentService->createPending([
            'business_id' => $this->apolloSoft->id,
            'subscription_id' => $subscription->id,
            'amount' => 50_000,
            'currency' => 'UGX',
            'method' => 'gateway',
            'payment_type' => 'onboarding',
            'gateway_name' => 'pesapal',
        ]);

        $this->paymentService->complete($payment, 'bypass');

        // Since no_trial plan has trial_days=0 and trial_used=false,
        // activateAfterOnboarding should go directly to ACTIVE
        $activated = $this->subscriptionService->activateAfterOnboarding($subscription->fresh());

        $this->assertSame(SubscriptionStatus::ACTIVE, $activated->status);
        $this->assertTrue($activated->onboarding_fee_paid);
        $this->assertNotNull($activated->approved_at);
    }

    public function test_margaret_hamilton_lacks_access_when_past_due_before_payment(): void
    {
        $subscription = $this->subscriptionService->subscribe(
            $this->apolloSoft->id,
            $this->noTrial->id,
            'monthly',
        );

        // Past_due without grace_used doesn't get a grace period automatically.
        // But the subscribe method doesn't set grace_period_ends_at.
        // hasAccess checks: PAST_DUE => grace_period_ends_at?->isFuture()
        // Since grace_period_ends_at is null, it returns false.
        // This is correct — user must pay onboarding to continue.
        $this->assertFalse($this->subscriptionService->hasAccess($this->apolloSoft->id));
    }

    // ════════════════════════════════════════════════════════════════════
    //  USER STORY 4 — Tim Berners-Lee: Upgrade from Essential → Enterprise
    // ════════════════════════════════════════════════════════════════════

    public function test_tim_berners_lee_upgrades_immediately(): void
    {
        $subscription = $this->subscribeAndActivateEssential($this->webFoundation);
        $subscription = $subscription->fresh();

        // Tim upgrades from Essential (UGX 75,000/mo) to Enterprise (UGX 500,000/mo) immediately
        $change = $this->scheduledChangeService->schedulePlanChange(
            $subscription->id,
            $this->enterprise->id,
            'upgrade',
        );

        $this->assertSame(ScheduledChangeType::UPGRADE, $change->change_type);
        $this->assertSame($subscription->plan_id, $change->from_plan_id);
        $this->assertSame($this->enterprise->id, $change->to_plan_id);
        $this->assertEquals(\App\Enums\Billing\ScheduledChangeStatus::PENDING, $change->status);

        // Apply immediate upgrade
        $this->subscriptionService->update($subscription->id, ['plan_id' => $this->enterprise->id]);
        $subscription->refresh();

        $this->assertSame($this->enterprise->id, $subscription->plan_id);
        $this->assertSame(500_000, (int) $subscription->plan->price_monthly);
    }

    public function test_tim_berners_lee_gets_proration_quote_for_upgrade(): void
    {
        $subscription = $this->subscribeAndActivateEssential($this->webFoundation);
        $subscription = $subscription->fresh();

        $quote = $this->paymentQuoteService->getQuote($subscription, $this->enterprise->id);

        $this->assertSame($this->essential->id, $quote['current_plan']['id']);
        $this->assertSame($this->enterprise->id, $quote['new_plan']['id']);
        $this->assertSame(75_000, (int) $quote['current_plan']['price_monthly']);
        $this->assertSame(500_000, (int) $quote['new_plan']['price_monthly']);
        $this->assertArrayHasKey('proration', $quote);
        $this->assertArrayHasKey('proration_due', $quote['proration']);
        $this->assertGreaterThan(0, $quote['proration']['proration_due']);
    }

    public function test_tim_berners_lee_cannot_upgrade_to_same_plan(): void
    {
        $subscription = $this->subscribeAndActivateEssential($this->webFoundation);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('already on this plan');
        $this->scheduledChangeService->schedulePlanChange(
            $subscription->id,
            $this->essential->id,
            'upgrade',
        );
    }

    // ════════════════════════════════════════════════════════════════════
    //  USER STORY 5 — Ada Lovelace: Schedule downgrade from Essential → Starter
    // ════════════════════════════════════════════════════════════════════

    public function test_ada_lovelace_schedules_downgrade(): void
    {
        $subscription = $this->subscribeAndActivateEssential($this->analyticalEngine);

        $change = $this->scheduledChangeService->schedulePlanChange(
            $subscription->id,
            $this->noTrial->id,
            'downgrade',
        );

        $this->assertSame(ScheduledChangeType::DOWNGRADE, $change->change_type);
        $this->assertSame($this->essential->id, $change->from_plan_id);
        $this->assertSame($this->noTrial->id, $change->to_plan_id);
        $this->assertEquals(\App\Enums\Billing\ScheduledChangeStatus::PENDING, $change->status);

        // Effective at should be at the next billing date
        $this->assertNotNull($change->effective_at);
    }

    public function test_ada_lovelace_applies_pending_downgrade(): void
    {
        $subscription = $this->subscribeAndActivateEssential($this->analyticalEngine);

        $this->scheduledChangeService->schedulePlanChange(
            $subscription->id,
            $this->noTrial->id,
            'downgrade',
        );

        // Move effective_at to the past so it becomes due
        $change = SubscriptionScheduledChange::where('subscription_id', $subscription->id)->first();
        $change->update(['effective_at' => Carbon::now()->subHour()]);

        $this->scheduledChangeService->applyPendingChanges();

        $change->refresh();
        $subscription->refresh();

        $this->assertEquals(\App\Enums\Billing\ScheduledChangeStatus::APPLIED, $change->status);
        $this->assertSame($this->noTrial->id, $subscription->plan_id);
    }

    public function test_ada_lovelace_cancels_pending_downgrade(): void
    {
        $subscription = $this->subscribeAndActivateEssential($this->analyticalEngine);

        $this->scheduledChangeService->schedulePlanChange(
            $subscription->id,
            $this->noTrial->id,
            'downgrade',
        );

        $this->scheduledChangeService->cancelPendingChange($subscription->id);

        $pending = $this->scheduledChangeService->getPendingForSubscription($subscription->id);
        $this->assertNull($pending);
    }

    // ════════════════════════════════════════════════════════════════════
    //  USER STORY 6 — Linus Torvalds: Cancel at period end
    // ════════════════════════════════════════════════════════════════════

    public function test_linus_torvalds_cancels_at_period_end(): void
    {
        $subscription = $this->subscribeAndActivateEssential($this->linuxFdn);

        $cancelled = $this->subscriptionService->cancel($subscription->id, false);

        $this->assertSame(SubscriptionStatus::ACTIVE, $cancelled->status);
        $this->assertTrue($cancelled->isCancelAtPeriodEnd());
        $this->assertNull($cancelled->cancelled_at);
    }

    public function test_linus_torvalds_still_has_access_after_cancel_at_period_end(): void
    {
        $subscription = $this->subscribeAndActivateEssential($this->linuxFdn);

        $this->subscriptionService->cancel($subscription->id, false);
        $subscription->refresh();

        $this->assertTrue($this->subscriptionService->hasAccess($this->linuxFdn->id));
    }

    public function test_linus_torvalds_process_cancel_at_period_end(): void
    {
        $subscription = $this->subscribeAndActivateEssential($this->linuxFdn);

        $this->subscriptionService->cancel($subscription->id, false);
        $subscription->refresh();

        // Simulate period ending
        $subscription->update(['next_billing_date' => Carbon::now()->subDay()]);

        $processed = $this->subscriptionService->processCancelAtPeriodEnd();

        $this->assertSame(1, $processed);

        $subscription->refresh();
        $this->assertSame(SubscriptionStatus::CANCELLED, $subscription->status);
        $this->assertNotNull($subscription->cancelled_at);
    }

    // ════════════════════════════════════════════════════════════════════
    //  USER STORY 7 — Dennis Ritchie: Cancel immediately
    // ════════════════════════════════════════════════════════════════════

    public function test_dennis_ritchie_cancels_immediately(): void
    {
        $subscription = $this->subscribeAndActivateEssential($this->bellLabs);

        $cancelled = $this->subscriptionService->cancel($subscription->id, true);

        $this->assertSame(SubscriptionStatus::CANCELLED, $cancelled->status);
        $this->assertNotNull($cancelled->cancelled_at);
        $this->assertNotNull($cancelled->ends_at);
    }

    public function test_dennis_ritchie_loses_access_immediately(): void
    {
        $subscription = $this->subscribeAndActivateEssential($this->bellLabs);

        $this->subscriptionService->cancel($subscription->id, true);

        $this->assertFalse($this->subscriptionService->hasAccess($this->bellLabs->id));
    }

    public function test_dennis_ritchie_cannot_cancel_already_cancelled(): void
    {
        $subscription = $this->subscribeAndActivateEssential($this->bellLabs);

        $this->subscriptionService->cancel($subscription->id, true);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('already ended');
        $this->subscriptionService->cancel($subscription->id, true);
    }

    // ════════════════════════════════════════════════════════════════════
    //  USER STORY 8 — Grace Hopper: Use referral code during subscription
    // ════════════════════════════════════════════════════════════════════

    public function test_grace_hopper_subscribes_with_referral_code(): void
    {
        $this->markTestSkipped('Referral code flow requires a pre-existing referral code. Tested in ReferralLifecycleTest.');

        // This scenario is validated in ReferralLifecycleTest.php (17 tests).
    }

    // ════════════════════════════════════════════════════════════════════
    //  USER STORY 9 — Access checks for every subscription status
    // ════════════════════════════════════════════════════════════════════

    public function test_access_is_granted_for_trial_within_window(): void
    {
        $subscription = $this->subscriptionService->subscribe(
            $this->aceHardware->id,
            $this->essential->id,
            'monthly',
        );

        $this->assertTrue($subscription->isOnTrial());
        $this->assertTrue($this->subscriptionService->hasAccess($this->aceHardware->id));
    }

    public function test_access_is_denied_for_expired_trial_grace(): void
    {
        // Create subscription, expire the trial, then force the grace period into the past
        $subscription = $this->subscriptionService->subscribe(
            $this->enigmaTech->id,
            $this->professional->id,
            'monthly',
        );
        $subscription->update(['trial_ends_at' => Carbon::now()->subDay()]);
        $this->subscriptionService->processExpiredTrials();
        $subscription->refresh();

        // Should now be PAST_DUE with active grace
        $this->assertSame(SubscriptionStatus::PAST_DUE, $subscription->status);
        $this->assertTrue($subscription->isInGrace());

        // Force grace period into the past
        $subscription->update(['grace_period_ends_at' => Carbon::now()->subDay()]);

        // Calling hasAccess triggers processDueTransitions -> SUSPENDED
        $this->assertFalse($this->subscriptionService->hasAccess($this->enigmaTech->id));
        $subscription->refresh();

        $this->assertSame(SubscriptionStatus::SUSPENDED, $subscription->status);
    }

    public function test_access_is_granted_for_trial_grace_period(): void
    {
        $subscription = $this->subscriptionService->subscribe(
            $this->enigmaTech->id,
            $this->professional->id,
            'monthly',
        );

        $subscription->update(['trial_ends_at' => Carbon::now()->subDay()]);
        $this->subscriptionService->processExpiredTrials();

        $this->assertTrue($this->subscriptionService->hasAccess($this->enigmaTech->id));
    }

    public function test_access_is_granted_for_past_due_within_grace(): void
    {
        $subscription = $this->subscribeAndActivateEssential($this->aceHardware);

        // Mark past due (sets grace_period_ends_at to +7 days)
        $pastDue = $this->subscriptionService->markPastDue($subscription->fresh());

        $this->assertTrue($pastDue->isInGrace());
        $this->assertTrue($this->subscriptionService->hasAccess($this->aceHardware->id));
    }

    public function test_access_is_denied_for_past_due_with_expired_grace(): void
    {
        $subscription = $this->subscribeAndActivateEssential($this->aceHardware);

        $this->subscriptionService->markPastDue($subscription->fresh());
        $subscription->refresh();

        // Expire the grace period
        $subscription->update(['grace_period_ends_at' => Carbon::now()->subDay()]);

        $this->assertFalse($this->subscriptionService->hasAccess($this->aceHardware->id));
    }

    public function test_access_is_denied_for_suspended(): void
    {
        $subscription = Subscription::create([
            'business_id' => $this->aceHardware->id,
            'plan_id' => $this->essential->id,
            'status' => SubscriptionStatus::SUSPENDED,
            'billing_cycle' => 'monthly',
            'starts_at' => Carbon::now()->subMonth(),
            'suspended_at' => Carbon::now(),
        ]);

        $this->assertFalse($this->subscriptionService->hasAccess($this->aceHardware->id));
    }

    public function test_access_is_denied_for_cancelled(): void
    {
        $subscription = Subscription::create([
            'business_id' => $this->aceHardware->id,
            'plan_id' => $this->essential->id,
            'status' => SubscriptionStatus::CANCELLED,
            'billing_cycle' => 'monthly',
            'starts_at' => Carbon::now()->subMonth(),
            'cancelled_at' => Carbon::now(),
        ]);

        $this->assertFalse($this->subscriptionService->hasAccess($this->aceHardware->id));
    }

    public function test_access_is_denied_for_expired(): void
    {
        Subscription::create([
            'business_id' => $this->aceHardware->id,
            'plan_id' => $this->essential->id,
            'status' => SubscriptionStatus::EXPIRED,
            'billing_cycle' => 'monthly',
            'starts_at' => Carbon::now()->subYear(),
        ]);

        $this->assertFalse($this->subscriptionService->hasAccess($this->aceHardware->id));
    }

    public function test_access_is_denied_for_no_subscription(): void
    {
        $freshBusiness = Business::factory()->create(['owner_id' => $this->grace->id]);
        $this->assertFalse($this->subscriptionService->hasAccess($freshBusiness->id));
    }

    // ════════════════════════════════════════════════════════════════════
    //  USER STORY 10 — Past due → grace → suspend → reactivate
    // ════════════════════════════════════════════════════════════════════

    public function test_grace_hopper_goes_past_due_and_gets_grace_period(): void
    {
        $subscription = $this->subscribeAndActivateEssential($this->aceHardware);

        $pastDue = $this->subscriptionService->markPastDue($subscription->fresh());

        $this->assertSame(SubscriptionStatus::PAST_DUE, $pastDue->status);
        $this->assertNotNull($pastDue->grace_period_ends_at);
        $this->assertTrue($pastDue->grace_period_ends_at->isFuture());
        $this->assertTrue($pastDue->grace_used);
    }

    public function test_grace_hopper_gets_suspended_after_grace_expires(): void
    {
        $subscription = $this->subscribeAndActivateEssential($this->aceHardware);

        $this->subscriptionService->markPastDue($subscription->fresh());
        $subscription->refresh();

        // Expire the grace period
        $subscription->update(['grace_period_ends_at' => Carbon::now()->subDay()]);

        $suspended = $this->subscriptionService->processSuspensions();

        $this->assertSame(1, $suspended);

        $subscription->refresh();
        $this->assertSame(SubscriptionStatus::SUSPENDED, $subscription->status);
        $this->assertNotNull($subscription->suspended_at);
    }

    public function test_grace_hopper_reactivates_after_suspension(): void
    {
        $subscription = $this->subscribeAndActivateEssential($this->aceHardware);

        // Go through past_due → suspended
        $this->subscriptionService->markPastDue($subscription->fresh());
        $subscription->refresh();
        $subscription->update(['grace_period_ends_at' => Carbon::now()->subDay()]);
        $this->subscriptionService->processSuspensions();
        $subscription->refresh();

        $this->assertSame(SubscriptionStatus::SUSPENDED, $subscription->status);

        // Pay and reactivate
        $reactivated = $this->subscriptionService->reactivate($subscription);

        $this->assertSame(SubscriptionStatus::ACTIVE, $reactivated->status);
        $this->assertNull($reactivated->suspended_at);
        $this->assertNotNull($reactivated->approved_at);
        $this->assertTrue($reactivated->next_billing_date->isFuture());
    }

    public function test_grace_hopper_cannot_use_grace_twice(): void
    {
        $subscription = $this->subscribeAndActivateEssential($this->aceHardware);

        $this->subscriptionService->markPastDue($subscription->fresh());

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Only active subscriptions can become past due');
        $this->subscriptionService->markPastDue($subscription->fresh());
    }

    // ════════════════════════════════════════════════════════════════════
    //  USER STORY 11 — Renewal processing (cron simulation)
    // ════════════════════════════════════════════════════════════════════

    public function test_renewal_processing_marks_overdue_as_past_due(): void
    {
        $subscription = $this->subscribeAndActivateEssential($this->aceHardware);

        // Move next_billing_date to the past
        $subscription->update(['next_billing_date' => Carbon::now()->subDay()]);

        $processed = $this->subscriptionService->processRenewals();

        $this->assertSame(1, $processed);

        $subscription->refresh();
        $this->assertSame(SubscriptionStatus::PAST_DUE, $subscription->status);
        $this->assertNotNull($subscription->grace_period_ends_at);
    }

    public function test_renewal_processing_skips_cancel_at_period_end(): void
    {
        $subscription = $this->subscribeAndActivateEssential($this->aceHardware);

        // Cancel at period end
        $this->subscriptionService->cancel($subscription->id, false);
        $subscription->refresh();

        // Move next_billing_date to the past
        $subscription->update(['next_billing_date' => Carbon::now()->subDay()]);

        $processed = $this->subscriptionService->processRenewals();

        // Should be 0 because cancel_at_period_end subscriptions are not "renewable"
        $this->assertSame(0, $processed);
    }

    // ════════════════════════════════════════════════════════════════════
    //  USER STORY 12 — Cancel-at-period-end cron processing
    // ════════════════════════════════════════════════════════════════════

    public function test_cancel_at_period_end_processing(): void
    {
        $subscription = $this->subscribeAndActivateEssential($this->aceHardware);

        $this->subscriptionService->cancel($subscription->id, false);
        $subscription->refresh();

        // Move next_billing_date to the past to trigger cancellation
        $subscription->update(['next_billing_date' => Carbon::now()->subDay()]);

        $processed = $this->subscriptionService->processCancelAtPeriodEnd();

        $this->assertSame(1, $processed);

        $subscription->refresh();
        $this->assertSame(SubscriptionStatus::CANCELLED, $subscription->status);
        $this->assertNotNull($subscription->cancelled_at);
    }

    // ════════════════════════════════════════════════════════════════════
    //  USER STORY 13 — Duplicate subscription prevention
    // ════════════════════════════════════════════════════════════════════

    public function test_business_cannot_have_duplicate_subscriptions(): void
    {
        $this->subscriptionService->subscribe(
            $this->aceHardware->id,
            $this->essential->id,
            'monthly',
        );

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('already has a subscription');
        $this->subscriptionService->subscribe(
            $this->aceHardware->id,
            $this->professional->id,
            'monthly',
        );
    }

    // ════════════════════════════════════════════════════════════════════
    //  USER STORY 14 — Invalid state transitions
    // ════════════════════════════════════════════════════════════════════

    public function test_cannot_activate_non_trial_or_past_due_subscription(): void
    {
        $subscription = Subscription::create([
            'business_id' => $this->aceHardware->id,
            'plan_id' => $this->essential->id,
            'status' => SubscriptionStatus::SUSPENDED,
            'billing_cycle' => 'monthly',
            'starts_at' => Carbon::now(),
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Cannot activate');
        $this->subscriptionService->activateSubscription($subscription);
    }

    public function test_cannot_suspend_trial_subscription(): void
    {
        $subscription = $this->subscriptionService->subscribe(
            $this->aceHardware->id,
            $this->essential->id,
            'monthly',
        );

        $this->assertSame(SubscriptionStatus::TRIAL, $subscription->status);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Cannot suspend');
        $this->subscriptionService->suspend($subscription);
    }

    public function test_cannot_reactivate_non_suspended_subscription(): void
    {
        $subscription = $this->subscribeAndActivateEssential($this->aceHardware);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Cannot reactivate');
        $this->subscriptionService->reactivate($subscription);
    }

    public function test_cannot_activate_after_onboarding_for_non_past_due(): void
    {
        $subscription = $this->subscribeAndActivateEssential($this->aceHardware);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Cannot activate after onboarding');
        $this->subscriptionService->activateAfterOnboarding($subscription);
    }

    // ════════════════════════════════════════════════════════════════════
    //  USER STORY 15 — Payment lifecycle
    // ════════════════════════════════════════════════════════════════════

    public function test_payment_goes_through_full_lifecycle(): void
    {
        $subscription = $this->subscriptionService->subscribe(
            $this->aceHardware->id,
            $this->essential->id,
            'monthly',
        );

        // Create pending payment
        $payment = $this->paymentService->createPending([
            'business_id' => $this->aceHardware->id,
            'subscription_id' => $subscription->id,
            'amount' => 150_000,
            'currency' => 'UGX',
            'method' => 'gateway',
            'payment_type' => 'onboarding',
            'gateway_name' => 'pesapal',
        ]);

        $this->assertTrue($payment->isPending());

        // Complete
        $completed = $this->paymentService->complete($payment, 'gateway_response_ok');
        $this->assertTrue($completed->isCompleted());
        $this->assertNotNull($completed->paid_at);

        // Cannot complete again
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('already completed');
        $this->paymentService->complete($completed);
    }

    public function test_payment_can_fail(): void
    {
        $subscription = $this->subscriptionService->subscribe(
            $this->aceHardware->id,
            $this->essential->id,
            'monthly',
        );

        $payment = $this->paymentService->createPending([
            'business_id' => $this->aceHardware->id,
            'subscription_id' => $subscription->id,
            'amount' => 150_000,
            'currency' => 'UGX',
            'method' => 'gateway',
            'payment_type' => 'onboarding',
            'gateway_name' => 'pesapal',
        ]);

        $failed = $this->paymentService->fail($payment, 'Insufficient funds');

        $this->assertEquals(\App\Enums\Billing\PaymentStatus::FAILED, $failed->status);
        $this->assertSame('Insufficient funds', $failed->rejection_reason);
    }

    public function test_payment_can_be_refunded(): void
    {
        $subscription = $this->subscriptionService->subscribe(
            $this->aceHardware->id,
            $this->essential->id,
            'monthly',
        );

        $payment = $this->paymentService->createPending([
            'business_id' => $this->aceHardware->id,
            'subscription_id' => $subscription->id,
            'amount' => 150_000,
            'currency' => 'UGX',
            'method' => 'gateway',
            'payment_type' => 'onboarding',
            'gateway_name' => 'pesapal',
        ]);

        $this->paymentService->complete($payment);

        $refunded = $this->paymentService->refund($payment->fresh());
        $this->assertEquals(\App\Enums\Billing\PaymentStatus::REFUNDED, $refunded->status);
    }

    public function test_cannot_refund_non_completed_payment(): void
    {
        $subscription = $this->subscriptionService->subscribe(
            $this->aceHardware->id,
            $this->essential->id,
            'monthly',
        );

        $payment = $this->paymentService->createPending([
            'business_id' => $this->aceHardware->id,
            'subscription_id' => $subscription->id,
            'amount' => 150_000,
            'currency' => 'UGX',
            'method' => 'gateway',
            'payment_type' => 'onboarding',
            'gateway_name' => 'pesapal',
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('not completed');
        $this->paymentService->refund($payment);
    }

    public function test_cannot_fail_completed_payment(): void
    {
        $subscription = $this->subscriptionService->subscribe(
            $this->aceHardware->id,
            $this->essential->id,
            'monthly',
        );

        $payment = $this->paymentService->createPending([
            'business_id' => $this->aceHardware->id,
            'subscription_id' => $subscription->id,
            'amount' => 150_000,
            'currency' => 'UGX',
            'method' => 'gateway',
            'payment_type' => 'onboarding',
            'gateway_name' => 'pesapal',
        ]);

        $this->paymentService->complete($payment);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Cannot fail completed');
        $this->paymentService->fail($payment->fresh(), 'test');
    }

    // ════════════════════════════════════════════════════════════════════
    //  USER STORY 16 — Scheduled change: apply pending changes (cron)
    // ════════════════════════════════════════════════════════════════════

    public function test_apply_pending_changes_processes_due_scheduled_changes(): void
    {
        $subscription = $this->subscribeAndActivateEssential($this->webFoundation);

        // Schedule a plan change to Enterprise
        $change = $this->scheduledChangeService->schedulePlanChange(
            $subscription->id,
            $this->enterprise->id,
            'upgrade',
        );

        // Make it due
        $change->update(['effective_at' => Carbon::now()->subHour()]);

        $this->scheduledChangeService->applyPendingChanges();

        $change->refresh();
        $subscription->refresh();

        $this->assertEquals(\App\Enums\Billing\ScheduledChangeStatus::APPLIED, $change->status);
        $this->assertSame($this->enterprise->id, $subscription->plan_id);
    }

    public function test_apply_pending_changes_handles_scheduled_cancellation(): void
    {
        $subscription = $this->subscribeAndActivateEssential($this->linuxFdn);

        $change = $this->scheduledChangeService->scheduleCancellation($subscription->id);

        $change->update(['effective_at' => Carbon::now()->subHour()]);

        $this->scheduledChangeService->applyPendingChanges();

        $change->refresh();
        $subscription->refresh();

        $this->assertEquals(\App\Enums\Billing\ScheduledChangeStatus::APPLIED, $change->status);
        $this->assertSame(SubscriptionStatus::CANCELLED, $subscription->status);
    }

    public function test_cancel_scheduled_change_cancels_pending(): void
    {
        $subscription = $this->subscribeAndActivateEssential($this->linuxFdn);

        $this->scheduledChangeService->scheduleCancellation($subscription->id);

        $this->scheduledChangeService->cancelPendingChange($subscription->id);

        $pending = $this->scheduledChangeService->getPendingForSubscription($subscription->id);
        $this->assertNull($pending);
    }

    // ════════════════════════════════════════════════════════════════════
    //  HELPERS
    // ════════════════════════════════════════════════════════════════════

    protected function subscribeAndActivateEssential(Business $business): Subscription
    {
        $subscription = $this->subscriptionService->subscribe(
            $business->id,
            $this->essential->id,
            'monthly',
        );

        return $this->subscriptionService->activateSubscription($subscription);
    }
}
