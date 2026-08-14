<?php

namespace Tests\Unit\Billing;

use App\Enums\Billing\ScheduledChangeType;
use App\Enums\Billing\SubscriptionStatus;
use App\Models\SubscriptionScheduledChange;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Billing lifecycle tests with real user stories, real names, and actual Plan prices.
 *
 * Coverage:
 *   User Story 1  - Grace Hopper: register, subscribe to Essential (trial), pay onboarding, activate
 *   User Story 2  - Alan Turing: subscribe to Professional (trial), let trial expire
 *   User Story 3  - Margaret Hamilton: subscribe to Essential (no trial), pay onboarding, activate
 *   User Story 4  - Tim Berners-Lee: subscribe to Enterprise, upgrade to Enterprise
 *   User Story 5  - Ada Lovelace: subscribe to Essential, schedule downgrade
 *   User Story 6  - Linus Torvalds: subscribe to Essential, cancel at period end
 *   User Story 7  - Dennis Ritchie: subscribe to Essential, cancel immediately
 *   User Story 8  - Grace Hopper: use referral code during subscription
 */
class BillingLifecycleTest extends AbstractBillingLifecycleTestCase
{
    // ════════════════════════════════════════════════════════════════════
    //  USER STORY 1 - Grace Hopper: Trial → Pay onboarding → Active
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
        $this->assertSame((float) $this->essential->price_monthly_usd, (float) $subscription->price_monthly_usd);
        $this->assertSame((float) $this->essential->onboarding_fee_usd, (float) $subscription->onboarding_fee_usd);
        $this->assertSame((float) $this->essential->price_yearly_usd, (float) $subscription->price_yearly_usd);
        $this->assertSame(
            (bool) config('onboarding.fee_paid_on_create', false),
            $subscription->onboarding_fee_paid,
        );
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
    //  USER STORY 2 - Alan Turing: Subscribe to Professional, let trial expire
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
        $this->assertSame((float) $this->professional->price_monthly_usd, (float) $subscription->price_monthly_usd);
        $this->assertSame((float) $this->professional->onboarding_fee_usd, (float) $subscription->onboarding_fee_usd);
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
    //  USER STORY 3 - Margaret Hamilton: No-trial plan → Pay onboarding → Active
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
        $this->assertSame((float) $this->noTrial->price_monthly_usd, (float) $subscription->price_monthly_usd);
        $this->assertSame((float) $this->noTrial->onboarding_fee_usd, (float) $subscription->onboarding_fee_usd);
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
        // This is correct - user must pay onboarding to continue.
        $this->assertFalse($this->subscriptionService->hasAccess($this->apolloSoft->id));
    }

    // ════════════════════════════════════════════════════════════════════
    //  USER STORY 4 - Tim Berners-Lee: Upgrade from Essential → Enterprise
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
        $this->assertSame((float) $this->enterprise->price_monthly_usd, (float) $subscription->plan->price_monthly_usd);
    }

    public function test_tim_berners_lee_gets_proration_quote_for_upgrade(): void
    {
        $subscription = $this->subscribeAndActivateEssential($this->webFoundation);
        $subscription = $subscription->fresh();

        $quote = $this->paymentQuoteService->getQuote($subscription, $this->enterprise->id);

        $this->assertSame($this->essential->id, $quote['current_plan']['id']);
        $this->assertSame($this->enterprise->id, $quote['new_plan']['id']);
        $this->assertSame((float) $this->essential->price_monthly_usd, (float) $quote['current_plan']['price_monthly_usd']);
        $this->assertSame((float) $this->enterprise->price_monthly_usd, (float) $quote['new_plan']['price_monthly_usd']);
        $this->assertArrayHasKey('proration', $quote);
        $this->assertArrayHasKey('proration_due', $quote['proration']);
        $this->assertSame((float) $this->enterprise->price_monthly_usd, (float) $quote['proration']['charge']);
        $this->assertGreaterThan(0, $quote['proration']['proration_due']);
    }

    public function test_trial_upgrade_quote_charges_full_price_with_no_credit(): void
    {
        $subscription = $this->subscriptionService->subscribe(
            $this->aceHardware->id,
            $this->professional->id,
            'monthly',
        );

        $quote = $this->paymentQuoteService->getQuote($subscription, $this->enterprise->id);

        $this->assertSame((float) $this->professional->price_monthly_usd, (float) $quote['proration']['old_price']);
        $this->assertSame((float) $this->enterprise->price_monthly_usd, (float) $quote['proration']['new_price']);
        $this->assertSame(0.0, (float) $quote['proration']['credit']);
        $this->assertSame(0.0, (float) $quote['proration']['credit_usd']);
        $this->assertSame((float) $this->enterprise->price_monthly_usd, (float) $quote['proration']['charge']);
        $this->assertSame((float) $this->enterprise->price_monthly_usd, (float) $quote['proration']['proration_due']);
    }

    public function test_active_upgrade_quote_charges_full_new_price_minus_unused_credit(): void
    {
        $subscription = $this->subscribeAndActivateEssential($this->webFoundation);
        $subscription = $subscription->fresh();

        $quote = $this->paymentQuoteService->getQuote($subscription, $this->enterprise->id);

        $now = Carbon::now()->startOfDay();
        $periodEnd = $subscription->next_billing_date->copy()->startOfDay();
        $periodStart = $periodEnd->copy()->subMonth()->startOfDay();
        $daysInPeriod = max(1, (int) $periodStart->diffInDays($periodEnd));
        // Paid coverage starts when the preserved trial ends (or now if the trial
        // already lapsed) - free trial days are never credited toward an upgrade.
        $paidStart = $subscription->trial_ends_at && $subscription->trial_ends_at->startOfDay()->gt($now)
            ? $subscription->trial_ends_at->copy()->startOfDay()
            : $now;
        $daysRemaining = $periodEnd->lte($paidStart) ? 0 : (int) $paidStart->diffInDays($periodEnd);
        $credit = round((float) $this->essential->price_monthly_usd * ($daysRemaining / $daysInPeriod), 2);
        $charge = (float) $this->enterprise->price_monthly_usd;

        $this->assertSame($charge, (float) $quote['proration']['charge']);
        $this->assertSame($credit, (float) $quote['proration']['credit']);
        $this->assertSame(round(max(0, $charge - $credit), 2), (float) $quote['proration']['proration_due']);
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
    //  USER STORY 5 - Ada Lovelace: Schedule downgrade from Essential → Starter
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
    //  USER STORY 6 - Linus Torvalds: Cancel at period end
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
    //  USER STORY 7 - Dennis Ritchie: Cancel immediately
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
    //  USER STORY 8 - Grace Hopper: Use referral code during subscription
    // ════════════════════════════════════════════════════════════════════

    public function test_grace_hopper_subscribes_with_referral_code(): void
    {
        $this->markTestSkipped('Referral code flow requires a pre-existing referral code. Tested in ReferralLifecycleTest.');

        // This scenario is validated in ReferralLifecycleTest.php (17 tests).
    }
}
