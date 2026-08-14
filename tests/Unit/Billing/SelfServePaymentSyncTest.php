<?php

namespace Tests\Unit\Billing;

use App\Enums\Billing\PaymentStatus;
use App\Enums\Billing\SubscriptionStatus;
use App\Services\Payment\Gateways\PesaPalGateway;
use Illuminate\Support\Carbon;

/**
 * Self-serve payment sync - the /billing/payments/{id}/confirm endpoint lets a
 * user reconcile a payment that PesaPal collected but whose webhook/IPN never
 * reached us (e.g. a network failure after payment).
 *
 * confirmPayment() asks the gateway for the real transaction status
 * (GetTransactionStatus) and, when successful, runs the SAME autoApprove path
 * the webhook uses - so the subscription is applied and access granted exactly
 * as if the webhook had arrived.
 */
class SelfServePaymentSyncTest extends AbstractBillingLifecycleTestCase
{
    private const TXN_ID = 'self-serve-txn-001';

    private function mockGatewayVerifySuccess(): void
    {
        $this->mock(PesaPalGateway::class, function ($mock) {
            $mock->shouldReceive('verify')->andReturn([
                'success' => true,
                'status' => 'successful',
                'transaction_id' => self::TXN_ID,
                'message' => 'Verified',
            ]);
        });
    }

    public function test_confirm_activates_subscription_when_gateway_says_paid(): void
    {
        $subscription = $this->subscribeAndActivateEssential($this->aceHardware);
        $subscription->update(['status' => SubscriptionStatus::SUSPENDED->value]);
        $subscription = $subscription->fresh();
        $this->assertSame(SubscriptionStatus::SUSPENDED, $subscription->status);

        $payment = $this->paymentService->createPending([
            'business_id' => $this->aceHardware->id,
            'subscription_id' => $subscription->id,
            'amount' => (float) $this->essential->price_monthly_usd,
            'currency' => 'USD',
            'method' => 'gateway',
            'payment_type' => 'subscription',
            'gateway_name' => 'pesapal',
            'gateway_transaction_id' => self::TXN_ID,
        ]);
        $this->assertTrue($payment->isPending());

        $this->mockGatewayVerifySuccess();

        $result = $this->gatewayService->confirmPayment($payment->id);

        $this->assertTrue($result['success'], 'confirm should report success');
        $this->assertSame('Payment confirmed. Subscription activated.', $result['message']);

        $payment->refresh();
        $subscription->refresh();

        $this->assertSame(PaymentStatus::COMPLETED, $payment->status, 'payment must be marked completed');
        $this->assertNotNull($payment->approved_at, 'payment must record an approval timestamp');
        $this->assertSame(SubscriptionStatus::ACTIVE, $subscription->status, 'subscription must be reactivated like autoApprove does');
    }

    public function test_confirm_applies_onboarding_payment_and_starts_subscription(): void
    {
        $subscription = $this->subscriptionService->subscribe(
            $this->apolloSoft->id,
            $this->essential->id,
            'monthly',
        );
        $this->assertSame(SubscriptionStatus::TRIAL, $subscription->status);

        $payment = $this->paymentService->createPending([
            'business_id' => $this->apolloSoft->id,
            'subscription_id' => $subscription->id,
            'amount' => (float) $this->essential->onboarding_fee_usd,
            'currency' => 'USD',
            'method' => 'gateway',
            'payment_type' => 'onboarding',
            'gateway_name' => 'pesapal',
            'gateway_transaction_id' => self::TXN_ID,
            'metadata' => ['plan_id' => $this->essential->id, 'billing_cycle' => 'monthly'],
        ]);

        $this->mockGatewayVerifySuccess();

        $result = $this->gatewayService->confirmPayment($payment->id);
        $this->assertTrue($result['success']);

        $payment->refresh();
        $subscription->refresh();

        $this->assertSame(PaymentStatus::COMPLETED, $payment->status);
        $this->assertTrue((bool) $subscription->onboarding_fee_paid, 'onboarding fee must be marked paid');
        // Onboarding payment grants access by paying the setup fee and starting
        // the trial - the subscription stays in TRIAL (with a future trial end).
        $this->assertContains($subscription->status, [SubscriptionStatus::TRIAL, SubscriptionStatus::ACTIVE], 'onboarding payment must leave the subscription usable');
        $this->assertTrue($subscription->trial_ends_at === null || $subscription->trial_ends_at->isFuture(), 'trial must not be in the past after onboarding');
    }

    public function test_confirm_applies_upgrade_payment_and_moves_plan(): void
    {
        $subscription = $this->subscribeAndActivateEssential($this->enigmaTech);
        $this->assertSame($this->essential->id, $subscription->plan_id);

        $payment = $this->paymentService->createPending([
            'business_id' => $this->enigmaTech->id,
            'subscription_id' => $subscription->id,
            'amount' => (float) $this->professional->price_monthly_usd,
            'currency' => 'USD',
            'method' => 'gateway',
            'payment_type' => 'upgrade_proration',
            'gateway_name' => 'pesapal',
            'gateway_transaction_id' => self::TXN_ID,
            'metadata' => ['to_plan_id' => $this->professional->id, 'billing_cycle' => 'monthly'],
        ]);

        $this->mockGatewayVerifySuccess();

        $result = $this->gatewayService->confirmPayment($payment->id);
        $this->assertTrue($result['success']);

        $subscription->refresh();

        $this->assertSame(PaymentStatus::COMPLETED, $payment->refresh()->status);
        $this->assertSame($this->professional->id, $subscription->plan_id, 'upgrade payment must move the subscription onto the paid plan');
        $this->assertSame(SubscriptionStatus::ACTIVE, $subscription->status);
    }

    public function test_confirm_applies_topup_and_extends_next_billing_date(): void
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
            'payment_type' => 'topup',
            'gateway_name' => 'pesapal',
            'gateway_transaction_id' => self::TXN_ID,
            'metadata' => ['topup_months' => 3],
        ]);

        $this->mockGatewayVerifySuccess();

        $result = $this->gatewayService->confirmPayment($payment->id);
        $this->assertTrue($result['success']);

        $subscription->refresh();

        $this->assertSame(PaymentStatus::COMPLETED, $payment->refresh()->status);
        $this->assertSame(SubscriptionStatus::ACTIVE, $subscription->status);
        $this->assertTrue(
            $subscription->next_billing_date->greaterThan($originalNextBilling),
            'top-up must extend next_billing_date beyond the original schedule',
        );
    }

    public function test_confirm_returns_pending_when_gateway_has_not_confirmed(): void
    {
        $subscription = $this->subscribeAndActivateEssential($this->linuxFdn);

        $payment = $this->paymentService->createPending([
            'business_id' => $this->linuxFdn->id,
            'subscription_id' => $subscription->id,
            'amount' => (float) $this->essential->price_monthly_usd,
            'currency' => 'USD',
            'method' => 'gateway',
            'payment_type' => 'subscription',
            'gateway_name' => 'pesapal',
            'gateway_transaction_id' => self::TXN_ID,
        ]);

        // Gateway has no confirmation yet - verify returns pending.
        $this->mock(PesaPalGateway::class, function ($mock) {
            $mock->shouldReceive('verify')->andReturn([
                'success' => false,
                'status' => 'pending',
                'message' => 'Transaction not yet paid.',
            ]);
        });

        $result = $this->gatewayService->confirmPayment($payment->id);

        $this->assertFalse($result['success'], 'confirm must report not-yet-confirmed');
        $this->assertStringContainsString('not yet confirmed', $result['message']);

        $payment->refresh();
        $subscription->refresh();

        $this->assertTrue($payment->isPending(), 'payment must stay pending when gateway has not confirmed');
        $this->assertNotSame(PaymentStatus::COMPLETED, $payment->status);
        $this->assertSame(SubscriptionStatus::ACTIVE, $subscription->status, 'subscription must be left untouched');
    }

    public function test_confirm_is_idempotent_for_already_completed_payment(): void
    {
        $subscription = $this->subscribeAndActivateEssential($this->webFoundation);

        $payment = $this->paymentService->createPending([
            'business_id' => $this->webFoundation->id,
            'subscription_id' => $subscription->id,
            'amount' => (float) $this->essential->price_monthly_usd,
            'currency' => 'USD',
            'method' => 'gateway',
            'payment_type' => 'subscription',
            'gateway_name' => 'pesapal',
            'gateway_transaction_id' => self::TXN_ID,
        ]);
        $payment->update(['status' => PaymentStatus::COMPLETED->value, 'approved_at' => Carbon::now()]);
        $payment = $payment->fresh();

        // Even without mocking the gateway, an already-completed payment short-circuits.
        $result = $this->gatewayService->confirmPayment($payment->id);

        $this->assertTrue($result['success'], 'already-completed payment should confirm instantly');
        $this->assertStringContainsString('already completed', $result['message']);
    }
}
