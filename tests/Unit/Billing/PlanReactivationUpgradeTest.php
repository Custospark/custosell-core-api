<?php

namespace Tests\Unit\Billing;

use App\Enums\Billing\SubscriptionStatus;
use App\Models\Subscription;
use App\Services\Payment\Gateways\Exceptions\GatewayException;
use App\Services\Payment\Validation\PaymentValidator;
use App\Services\SubscriptionService;
use App\Repositories\Contracts\PaymentRepositoryInterface;
use App\Services\Currency\Contracts\CurrencyExchangeServiceInterface;

/**
 * Regression coverage for the "reactivate / resubscribe stays on the OLD plan"
 * bug: a suspended (or otherwise reactivated) subscription was never moved onto
 * the plan the user actually paid for — plan_id, prices and next_billing_date
 * kept the identity of the pre-suspension plan.
 */
class PlanReactivationUpgradeTest extends AbstractBillingLifecycleTestCase
{
    private function suspendOnProfessional(\App\Models\Business $business): Subscription
    {
        $subscription = $this->subscriptionService->subscribe(
            $business->id,
            $this->professional->id,
            'monthly',
        );
        $subscription = $this->subscriptionService->activateSubscription($subscription);
        $this->subscriptionService->markPastDue($subscription);
        $this->subscriptionService->suspend($subscription);

        return $subscription->fresh();
    }

    public function test_reactivate_onto_higher_plan_updates_plan_identity(): void
    {
        $subscription = $this->suspendOnProfessional($this->bellLabs);

        $this->assertSame(SubscriptionStatus::SUSPENDED, $subscription->status);
        $this->assertSame($this->professional->id, $subscription->plan_id);

        $payment = $this->paymentService->createPending([
            'business_id' => $this->bellLabs->id,
            'subscription_id' => $subscription->id,
            'amount' => (float) $this->enterprise->price_monthly_usd,
            'currency' => 'USD',
            'method' => 'gateway',
            'payment_type' => 'subscription',
            'gateway_name' => 'pesapal',
            'gateway_transaction_id' => 'mock-txn-reactivate-higher',
            'metadata' => [
                'action' => 'reactivate',
                'to_plan_id' => $this->enterprise->id,
                'billing_cycle' => 'monthly',
            ],
        ]);

        $this->mock(\App\Services\Payment\Gateways\PesaPalGateway::class, function ($mock) {
            $mock->shouldReceive('verify')->andReturn([
                'success' => true,
                'status' => 'successful',
                'transaction_id' => 'mock-txn-reactivate-higher',
                'message' => 'Verified',
            ]);
        });

        $this->gatewayService->confirmPayment($payment->id);

        $subscription = $subscription->fresh();

        $this->assertSame(SubscriptionStatus::ACTIVE, $subscription->status);
        $this->assertSame($this->enterprise->id, $subscription->plan_id, 'plan_id must move to the plan the user paid for');
        $this->assertSame((float) $this->enterprise->price_monthly_usd, (float) $subscription->price_monthly_usd, 'charged price must follow the new plan');
        $this->assertTrue($subscription->next_billing_date->isFuture());
    }

    public function test_reactivate_onto_same_plan_keeps_plan_identity(): void
    {
        $subscription = $this->suspendOnProfessional($this->analyticalEngine);

        $payment = $this->paymentService->createPending([
            'business_id' => $this->analyticalEngine->id,
            'subscription_id' => $subscription->id,
            'amount' => (float) $this->professional->price_monthly_usd,
            'currency' => 'USD',
            'method' => 'gateway',
            'payment_type' => 'subscription',
            'gateway_name' => 'pesapal',
            'gateway_transaction_id' => 'mock-txn-reactivate-same',
            'metadata' => [
                'action' => 'reactivate',
                'to_plan_id' => $this->professional->id,
                'billing_cycle' => 'monthly',
            ],
        ]);

        $this->mock(\App\Services\Payment\Gateways\PesaPalGateway::class, function ($mock) {
            $mock->shouldReceive('verify')->andReturn([
                'success' => true,
                'status' => 'successful',
                'transaction_id' => 'mock-txn-reactivate-same',
                'message' => 'Verified',
            ]);
        });

        $this->gatewayService->confirmPayment($payment->id);

        $subscription = $subscription->fresh();

        $this->assertSame(SubscriptionStatus::ACTIVE, $subscription->status);
        $this->assertSame($this->professional->id, $subscription->plan_id);
    }

    public function test_reactivate_without_target_plan_keeps_current_plan(): void
    {
        $subscription = $this->suspendOnProfessional($this->enigmaTech);

        $payment = $this->paymentService->createPending([
            'business_id' => $this->enigmaTech->id,
            'subscription_id' => $subscription->id,
            'amount' => (float) $this->professional->price_monthly_usd,
            'currency' => 'USD',
            'method' => 'gateway',
            'payment_type' => 'subscription',
            'gateway_name' => 'pesapal',
            'gateway_transaction_id' => 'mock-txn-reactivate-plain',
        ]);

        $this->mock(\App\Services\Payment\Gateways\PesaPalGateway::class, function ($mock) {
            $mock->shouldReceive('verify')->andReturn([
                'success' => true,
                'status' => 'successful',
                'transaction_id' => 'mock-txn-reactivate-plain',
                'message' => 'Verified',
            ]);
        });

        $this->gatewayService->confirmPayment($payment->id);

        $subscription = $subscription->fresh();

        $this->assertSame(SubscriptionStatus::ACTIVE, $subscription->status);
        $this->assertSame($this->professional->id, $subscription->plan_id);
    }

    public function test_amount_validation_prices_subscription_payment_against_target_plan(): void
    {
        $subscription = $this->suspendOnProfessional($this->apolloSoft);

        $validator = new PaymentValidator(
            $this->createMock(CurrencyExchangeServiceInterface::class),
            $this->createMock(PaymentRepositoryInterface::class),
        );

        // A subscription payment bearing to_plan_id must be validated against the
        // TARGET plan's price, not the OLD plan held by the suspended subscription.
        $validator->validatePaymentAmount($subscription, [
            'amount' => (float) $this->enterprise->price_monthly_usd,
            'currency' => 'USD',
            'payment_type' => 'subscription',
            'billing_cycle' => 'monthly',
            'gateway_name' => 'pesapal',
            'metadata' => ['to_plan_id' => $this->enterprise->id],
        ]);

        $this->expectException(GatewayException::class);
        $this->expectExceptionMessage('does not match expected amount');
        $validator->validatePaymentAmount($subscription, [
            'amount' => (float) $this->professional->price_monthly_usd,
            'currency' => 'USD',
            'payment_type' => 'subscription',
            'billing_cycle' => 'monthly',
            'gateway_name' => 'pesapal',
            'metadata' => ['to_plan_id' => $this->enterprise->id],
        ]);
    }
}