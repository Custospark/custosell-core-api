<?php

namespace Tests\Unit\Billing;

use App\Enums\Billing\SubscriptionStatus;
use Illuminate\Support\Carbon;

/**
 * Renewal top-up - an active subscription can prepay a chosen number of months
 * at a rate prorated to its stored billing cycle (monthly rate on monthly,
 * yearly/12 on yearly), anchored to the existing next_billing_date.
 */
class TopUpRenewalTest extends AbstractBillingLifecycleTestCase
{
    public function test_monthly_topup_charges_monthly_rate_per_month(): void
    {
        $subscription = $this->subscribeAndActivateEssential($this->aceHardware);
        $subscription = $subscription->fresh();
        $original = $subscription->next_billing_date;

        $renewed = $this->subscriptionService->renewEarly($subscription, 3);

        $expectedDate = $original->copy()->addMonths(3);
        $this->assertSame($expectedDate->toDateTimeString(), $renewed->next_billing_date->toDateTimeString());
        $this->assertSame(3, (int) ($renewed->metadata['topup_months'] ?? 0));
    }

    public function test_yearly_topup_prorates_yearly_rate_per_month(): void
    {
        $subscription = $this->subscribeAndActivateEssential($this->enigmaTech);
        $this->subscriptionService->changeBillingCycle($subscription->fresh(), 'yearly');
        $subscription = $subscription->fresh();
        $original = $subscription->next_billing_date;

        $renewed = $this->subscriptionService->renewEarly($subscription, 3);

        $expectedDate = $original->copy()->addMonths(3);
        $this->assertSame($expectedDate->toDateTimeString(), $renewed->next_billing_date->toDateTimeString());
        $this->assertSame(3, (int) ($renewed->metadata['topup_months'] ?? 0));
    }

    public function test_authoritative_topup_amount_monthly(): void
    {
        $subscription = $this->subscribeAndActivateEssential($this->webFoundation);
        $subscription = $subscription->fresh();
        $this->webFoundation->update(['currency' => 'USD']);

        $this->mock(\App\Services\Payment\Gateways\PesaPalGateway::class, function ($mock) {
            $mock->shouldReceive('isEnabled')->andReturn(true);
            $mock->shouldReceive('getSupportedCurrencies')->andReturn(['UGX', 'KES', 'TZS', 'USD']);
            $mock->shouldReceive('initiate')->andReturn([
                'gateway_txn_id' => 'mock-txn-' . uniqid(),
                'gateway_ref' => 'mock-ref-' . uniqid(),
                'type' => 'redirect',
                'redirect_url' => 'https://pay.pesapal.com/mock',
                'message' => 'Success',
                'raw_response' => [],
            ]);
        });

        $result = $this->gatewayService->initiatePayment($subscription, 'pesapal', [
            'amount' => 1,
            'currency' => 'USD',
            'payment_type' => 'topup',
            'topup_months' => 6,
        ]);

        // Essential monthly = $20 → 6 months = $120 (server-authoritative, client sent $1).
        $payment = $this->paymentService->getById($result['payment_id']);
        $this->assertSame(120.0, (float) $payment->amount);
        $this->assertSame(6, (int) ($payment->metadata['topup_months'] ?? 0));
        $this->assertSame('topup', $payment->payment_type->value);
    }

    public function test_authoritative_topup_amount_yearly(): void
    {
        $subscription = $this->subscribeAndActivateEssential($this->linuxFdn);
        $this->subscriptionService->changeBillingCycle($subscription->fresh(), 'yearly');
        $subscription = $subscription->fresh();
        $this->linuxFdn->update(['currency' => 'USD']);

        $this->mock(\App\Services\Payment\Gateways\PesaPalGateway::class, function ($mock) {
            $mock->shouldReceive('isEnabled')->andReturn(true);
            $mock->shouldReceive('getSupportedCurrencies')->andReturn(['UGX', 'KES', 'TZS', 'USD']);
            $mock->shouldReceive('initiate')->andReturn([
                'gateway_txn_id' => 'mock-txn-' . uniqid(),
                'gateway_ref' => 'mock-ref-' . uniqid(),
                'type' => 'redirect',
                'redirect_url' => 'https://pay.pesapal.com/mock',
                'message' => 'Success',
                'raw_response' => [],
            ]);
        });

        $result = $this->gatewayService->initiatePayment($subscription, 'pesapal', [
            'amount' => 1,
            'currency' => 'USD',
            'payment_type' => 'topup',
            'topup_months' => 6,
        ]);

        // Essential yearly = $200 → yearly/12 = 16.6667/mo → 6 months = $100.
        $payment = $this->paymentService->getById($result['payment_id']);
        $this->assertSame(100.0, (float) $payment->amount);
    }

    public function test_topup_rejected_without_months(): void
    {
        $subscription = $this->subscribeAndActivateEssential($this->bellLabs);
        $subscription = $subscription->fresh();

        $this->mock(\App\Services\Payment\Gateways\PesaPalGateway::class, function ($mock) {
            $mock->shouldReceive('isEnabled')->andReturn(true);
            $mock->shouldReceive('getSupportedCurrencies')->andReturn(['UGX', 'KES', 'TZS', 'USD']);
        });

        $this->expectException(\App\Services\Payment\Gateways\Exceptions\GatewayException::class);
        $this->gatewayService->initiatePayment($subscription, 'pesapal', [
            'amount' => 1,
            'currency' => 'USD',
            'payment_type' => 'topup',
        ]);
    }

    public function test_topup_payment_approval_extends_by_selected_months(): void
    {
        $subscription = $this->subscribeAndActivateEssential($this->analyticalEngine);
        $subscription = $subscription->fresh();
        $original = $subscription->next_billing_date;

        $payment = $this->paymentService->createPending([
            'business_id' => $this->analyticalEngine->id,
            'subscription_id' => $subscription->id,
            'amount' => 60.0,
            'currency' => 'USD',
            'method' => 'gateway',
            'payment_type' => 'topup',
            'gateway_name' => 'pesapal',
            'gateway_transaction_id' => 'mock-txn-topup',
            'metadata' => ['topup_months' => 3],
        ]);

        $this->mock(\App\Services\Payment\Gateways\PesaPalGateway::class, function ($mock) {
            $mock->shouldReceive('verify')->andReturn([
                'success' => true,
                'status' => 'successful',
                'transaction_id' => 'mock-txn-topup',
                'message' => 'Verified',
            ]);
        });

        $this->gatewayService->confirmPayment($payment->id);

        $subscription->refresh();

        $expected = $original->copy()->addMonths(3);
        $this->assertSame(SubscriptionStatus::ACTIVE, $subscription->status);
        $this->assertSame($expected->toDateTimeString(), $subscription->next_billing_date->toDateTimeString());
        $this->assertSame(3, (int) ($subscription->metadata['topup_months'] ?? 0));
    }

    public function test_topup_fails_when_not_active(): void
    {
        $subscription = $this->subscriptionService->subscribe(
            $this->apolloSoft->id,
            $this->essential->id,
            'monthly',
        );

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Cannot renew subscription early');
        $this->subscriptionService->renewEarly($subscription, 3);
    }

    public function test_topup_fails_when_cancel_at_period_end(): void
    {
        $subscription = $this->subscribeAndActivateEssential($this->linuxFdn);

        $this->subscriptionService->cancel($subscription->id, false);
        $subscription = $subscription->fresh();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('set to cancel');
        $this->subscriptionService->renewEarly($subscription, 3);
    }

    public function test_renew_early_without_months_defaults_to_one_stored_period(): void
    {
        $subscription = $this->subscribeAndActivateEssential($this->aceHardware);
        $subscription = $subscription->fresh();
        $original = $subscription->next_billing_date;

        // Monthly sub, no months arg → 1 month.
        $renewed = $this->subscriptionService->renewEarly($subscription);
        $this->assertSame($original->copy()->addMonth()->toDateTimeString(), $renewed->next_billing_date->toDateTimeString());
        $this->assertSame(1, (int) ($renewed->metadata['topup_months'] ?? 0));
    }
}
