<?php

namespace Tests\Unit\Billing;

use App\Enums\Billing\DiscountType;
use App\Enums\Billing\PaymentType;
use App\Enums\Billing\ReferralCodeOwnerType;
use App\Enums\Billing\ReferralStatus;
use App\Enums\Billing\RewardType;
use App\Models\Plan;
use App\Models\ReferralCode;

/**
 * Pre-subscription referral application — a fresh, never-subscribed business
 * applies a referral/coupon code BEFORE any subscription exists. The referral
 * is stored against the business (subscription_id null) and stays PENDING; the
 * first initiatePayment binds it to the new subscription and applies the
 * discount to the authoritative charge the gateway receives.
 */
class ReferralPreSubscriptionTest extends AbstractBillingLifecycleTestCase
{
    private \App\Services\ReferralService $referralService;

    protected function setUp(): void
    {
        parent::setUp();
        $this->referralService = app(\App\Services\ReferralService::class);
    }

    private function createReferralCode(string $code, DiscountType $type, float $value): ReferralCode
    {
        return ReferralCode::create([
            'owner_type' => ReferralCodeOwnerType::BUSINESS,
            'owner_user_id' => $this->alan->id,
            'owner_business_id' => $this->enigmaTech->id,
            'code' => $code,
            'discount_type' => $type,
            'discount_value' => $value,
            'reward_type' => RewardType::PERCENTAGE,
            'reward_value' => 5,
            'is_active' => true,
        ]);
    }

    private function mockPesapalGateway(): void
    {
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
    }

    public function test_pre_subscription_apply_stores_pending_referral_with_null_subscription(): void
    {
        $personal = Plan::where('slug', 'personal')->first();
        $code = $this->createReferralCode('WELCOME10', DiscountType::PERCENTAGE, 10);

        // Business has NO subscription yet — code applied against the business.
        $referral = $this->referralService->processReferral(
            $code->code,
            null,
            $this->aceHardware->id,
            ['plan_id' => $personal->id, 'billing_cycle' => 'monthly'],
        );

        $this->assertNull($referral->subscription_id, 'pre-subscription referral has no subscription');
        $this->assertSame($this->aceHardware->id, $referral->referred_business_id);
        $this->assertEquals(ReferralStatus::PENDING, $referral->status);
        // Personal monthly = $10 → 10% = $1.00 informational figure for the modal.
        $this->assertSame(1.0, (float) $referral->discount_applied);
        $this->assertSame(0, (int) $referral->reward_amount, 'no money captured before payment');
    }

    public function test_first_initiate_payment_binds_referral_and_discounts_personal_monthly(): void
    {
        $personal = Plan::where('slug', 'personal')->first();
        $code = $this->createReferralCode('PERSONAL10', DiscountType::PERCENTAGE, 10);

        $this->referralService->processReferral(
            $code->code,
            null,
            $this->aceHardware->id,
            ['plan_id' => $personal->id, 'billing_cycle' => 'monthly'],
        );

        // Now the business subscribes and pays.
        $subscription = $this->subscriptionService->subscribe(
            $this->aceHardware->id,
            $personal->id,
            'monthly',
        );
        $this->aceHardware->update(['currency' => 'USD']);
        $this->mockPesapalGateway();

        $result = $this->gatewayService->initiatePayment($subscription, 'pesapal', [
            'amount' => 0,
            'currency' => 'USD',
            'payment_type' => PaymentType::SUBSCRIPTION->value,
            'billing_cycle' => 'monthly',
        ]);

        // PENDING business-level referral got bound to the new subscription.
        $bound = \App\Models\Referral::where('referred_business_id', $this->aceHardware->id)->first();
        $this->assertSame($subscription->id, $bound->subscription_id, 'first initiate binds the referral');
        $this->assertEquals(ReferralStatus::PENDING, $bound->status, 'still pending until payment confirms');
        $this->assertSame(1.0, (float) $bound->discount_applied);

        // Personal monthly $10 − 10% ($1) = $9 = authoritative charge.
        $payment = $this->paymentService->getById($result['payment_id']);
        $this->assertSame(9.0, (float) $payment->amount);
        $this->assertEquals(PaymentType::SUBSCRIPTION, $payment->payment_type);
    }

    public function test_first_initiate_payment_discounts_personal_yearly(): void
    {
        $personal = Plan::where('slug', 'personal')->first();
        $code = $this->createReferralCode('PERSONALYEAR', DiscountType::PERCENTAGE, 10);

        $this->referralService->processReferral(
            $code->code,
            null,
            $this->aceHardware->id,
            ['plan_id' => $personal->id, 'billing_cycle' => 'yearly'],
        );

        $subscription = $this->subscriptionService->subscribe(
            $this->aceHardware->id,
            $personal->id,
            'yearly',
        );
        $this->aceHardware->update(['currency' => 'USD']);
        $this->mockPesapalGateway();

        $result = $this->gatewayService->initiatePayment($subscription, 'pesapal', [
            'amount' => 0,
            'currency' => 'USD',
            'payment_type' => PaymentType::SUBSCRIPTION->value,
            'billing_cycle' => 'yearly',
        ]);

        // Personal yearly $100 − 10% ($10) = $90.
        $payment = $this->paymentService->getById($result['payment_id']);
        $this->assertSame(90.0, (float) $payment->amount);
    }

    public function test_flat_discount_clamped_to_charge_on_first_payment(): void
    {
        $personal = Plan::where('slug', 'personal')->first();
        // Flat $5000 far exceeds the $10 Personal monthly charge — no negative fees.
        $code = $this->createReferralCode('BIGFLAT', DiscountType::FLAT_AMOUNT, 5000);

        $this->referralService->processReferral(
            $code->code,
            null,
            $this->aceHardware->id,
            ['plan_id' => $personal->id, 'billing_cycle' => 'monthly'],
        );

        $subscription = $this->subscriptionService->subscribe(
            $this->aceHardware->id,
            $personal->id,
            'monthly',
        );
        $this->aceHardware->update(['currency' => 'USD']);
        $this->mockPesapalGateway();

        $result = $this->gatewayService->initiatePayment($subscription, 'pesapal', [
            'amount' => 0,
            'currency' => 'USD',
            'payment_type' => PaymentType::SUBSCRIPTION->value,
            'billing_cycle' => 'monthly',
        ]);

        $payment = $this->paymentService->getById($result['payment_id']);
        $this->assertSame(0.0, (float) $payment->amount, 'flat discount clamps to the $10 charge');
    }
}