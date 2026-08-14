<?php

namespace Tests\Unit\Billing;

use App\Enums\Billing\SubscriptionStatus;
use Illuminate\Support\Carbon;

/**
 * Early renewal - an active subscription can prepay a full period, extending
 * next_billing_date from its existing value (schedule preserved, no proration).
 */
class EarlyRenewalTest extends AbstractBillingLifecycleTestCase
{
    public function test_active_subscription_renews_early_and_extends_existing_schedule(): void
    {
        $subscription = $this->subscribeAndActivateEssential($this->aceHardware);
        $subscription = $subscription->fresh();

        $originalNextBilling = $subscription->next_billing_date;
        $this->assertTrue($originalNextBilling->isFuture());

        $renewed = $this->subscriptionService->renewEarly($subscription);

        $this->assertSame(SubscriptionStatus::ACTIVE, $renewed->status);
        // Extends one full month from the EXISTING next_billing_date, not from today.
        $expected = $originalNextBilling->copy()->addMonth();
        $this->assertSame($expected->toDateTimeString(), $renewed->next_billing_date->toDateTimeString());
        $this->assertNull($renewed->grace_period_ends_at);
        $this->assertTrue($renewed->next_billing_date->greaterThan($originalNextBilling));
    }

    public function test_yearly_active_subscription_extends_by_one_year(): void
    {
        $subscription = $this->subscribeAndActivateEssential($this->enigmaTech);
        $this->subscriptionService->changeBillingCycle($subscription->fresh(), 'yearly');
        $subscription = $subscription->fresh();

        $originalNextBilling = $subscription->next_billing_date;

        $renewed = $this->subscriptionService->renewEarly($subscription);

        $expected = $originalNextBilling->copy()->addYear();
        $this->assertSame($expected->toDateTimeString(), $renewed->next_billing_date->toDateTimeString());
    }

    public function test_early_renewal_fails_when_not_active(): void
    {
        $subscription = $this->subscriptionService->subscribe(
            $this->apolloSoft->id,
            $this->essential->id,
            'monthly',
        );

        $this->assertSame(SubscriptionStatus::TRIAL, $subscription->status);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Cannot renew subscription early');
        $this->subscriptionService->renewEarly($subscription);
    }

    public function test_early_renewal_fails_when_cancel_at_period_end(): void
    {
        $subscription = $this->subscribeAndActivateEssential($this->webFoundation);

        $this->subscriptionService->cancel($subscription->id, false);
        $subscription = $subscription->fresh();

        $this->assertTrue($subscription->isCancelAtPeriodEnd());

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('set to cancel');
        $this->subscriptionService->renewEarly($subscription);
    }

    public function test_early_renewal_does_not_touch_referral_reward(): void
    {
        $subscription = $this->subscribeAndActivateEssential($this->linuxFdn);
        $subscription = $subscription->fresh();

        $referral = $subscription->referral;
        $originalReward = $referral ? (float) $referral->reward_amount : null;

        $this->subscriptionService->renewEarly($subscription);

        $subscription->refresh();
        $referralAfter = $subscription->referral;
        if ($originalReward === null) {
            $this->assertNull($referralAfter);
        } else {
            $this->assertSame($originalReward, (float) $referralAfter->reward_amount);
        }
    }

    public function test_payment_approval_uses_early_renewal_when_schedule_is_future(): void
    {
        $subscription = $this->subscribeAndActivateEssential($this->bellLabs);
        $subscription = $subscription->fresh();

        $originalNextBilling = $subscription->next_billing_date;

        $payment = $this->paymentService->createPending([
            'business_id' => $this->bellLabs->id,
            'subscription_id' => $subscription->id,
            'amount' => (float) $this->essential->price_monthly_usd,
            'currency' => 'USD',
            'method' => 'gateway',
            'payment_type' => 'renewal',
            'gateway_name' => 'pesapal',
            'gateway_transaction_id' => 'mock-txn-early-renewal',
        ]);

        // Mock the gateway verification so confirmPayment's approval path runs.
        $this->mock(\App\Services\Payment\Gateways\PesaPalGateway::class, function ($mock) {
            $mock->shouldReceive('verify')->andReturn([
                'success' => true,
                'status' => 'successful',
                'transaction_id' => 'mock-txn-early-renewal',
                'message' => 'Verified',
            ]);
        });

        $this->gatewayService->confirmPayment($payment->id);

        $subscription->refresh();

        $expected = $originalNextBilling->copy()->addMonth();
        $this->assertSame(SubscriptionStatus::ACTIVE, $subscription->status);
        $this->assertSame($expected->toDateTimeString(), $subscription->next_billing_date->toDateTimeString());
        $this->assertArrayHasKey('renewed_early_at', $subscription->metadata, 'renewed_early_at should be recorded');
    }

    public function test_renewal_after_period_end_uses_normal_renewal_not_early(): void
    {
        $subscription = $this->subscribeAndActivateEssential($this->analyticalEngine);
        $subscription->update(['next_billing_date' => Carbon::now()->subDay()]);
        $subscription = $subscription->fresh();

        $renewed = $this->subscriptionService->renewSubscription($subscription);

        $this->assertSame(SubscriptionStatus::ACTIVE, $renewed->status);
        $this->assertTrue($renewed->next_billing_date->isFuture());
        $this->assertFalse(isset($renewed->metadata['renewed_early_at']), 'on-time renewal should not be flagged early');
    }
}
