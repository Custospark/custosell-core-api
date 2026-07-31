<?php

namespace Tests\Unit\Billing;

use App\Enums\Billing\SubscriptionStatus;
use App\Models\Subscription;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Billing lifecycle tests with real user stories, real names, and actual Plan prices.
 *
 * Coverage:
 *   User Story 13 — Duplicate subscription prevention
 *   User Story 14 — Invalid state transitions
 *   User Story 15 — Payment lifecycle (pending → complete / fail / refund)
 *   User Story 16 — Scheduled change: apply pending changes (cron)
 */
class BillingPaymentLifecycleTest extends AbstractBillingLifecycleTestCase
{
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
}
