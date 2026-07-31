<?php

namespace Tests\Unit\Billing;

use App\Enums\Billing\SubscriptionStatus;
use App\Models\Business;
use App\Models\Subscription;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Billing lifecycle tests with real user stories, real names, and actual Plan prices.
 *
 * Coverage:
 *   User Story 9  — Access checks for every subscription status
 *   User Story 10 — Past due → grace → suspend → reactivate
 *   User Story 11 — Renewal processing
 *   User Story 12 — Cancel-at-period-end cron processing
 */
class BillingAccessLifecycleTest extends AbstractBillingLifecycleTestCase
{
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
}
