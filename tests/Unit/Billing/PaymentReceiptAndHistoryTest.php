<?php

namespace Tests\Unit\Billing;

use App\Mail\StandardEmail;
use App\Models\BillingPayment;
use App\Services\Billing\BillingHistoryService;
use App\Services\Payment\PaymentReceiptService;
use Illuminate\Support\Facades\Mail;
class PaymentReceiptAndHistoryTest extends AbstractBillingLifecycleTestCase
{
    protected function receiptService(): PaymentReceiptService
    {
        return app(PaymentReceiptService::class);
    }

    protected function makeCompletedTopUpPayment(): BillingPayment
    {
        $subscription = $this->subscribeAndActivateEssential($this->aceHardware);
        $subscription = $subscription->fresh();
        $subscription->update(['billing_cycle' => 'yearly']);

        $payment = $this->paymentService->createPending([
            'business_id' => $this->aceHardware->id,
            'subscription_id' => $subscription->id,
            'user_id' => $this->grace->id,
            'amount' => 60.0,
            'currency' => 'USD',
            'method' => 'gateway',
            'payment_type' => 'topup',
            'gateway_name' => 'pesapal',
            'gateway_transaction_id' => 'TXN-RCPT-1',
            'transaction_reference' => 'CUSTO-RCPT-1',
            'metadata' => ['topup_months' => 3, 'original_amount' => 60, 'credit_used' => 0],
        ]);

        return $this->paymentService->complete($payment, '{"status":"successful"}');
    }

    public function test_receipt_pdf_renders_and_includes_custospark_and_subscription_details(): void
    {
        $payment = $this->makeCompletedTopUpPayment();

        $data = $this->receiptService()->buildData($payment);
        $html = view(PaymentReceiptService::RECEIPT_VIEW, $data)->render();

        $this->assertStringContainsString('Custospark Company Ltd', $html);
        $this->assertStringContainsString('Custosell Subscription Receipt', $html);
        $this->assertStringContainsString('data:image/png;base64,', $html);
        $this->assertStringContainsString('header-logo', $html);
        $this->assertStringContainsString('report-title-logo', $html);
        $this->assertStringContainsString('info@custospark.com', $html);
        $this->assertStringContainsString('www.custospark.com', $html);
        $this->assertStringContainsString('Custosell - Essential', $html);
        $this->assertStringContainsString('CUSTO-RCPT-1', $html);
        $this->assertStringContainsString('TXN-RCPT-1', $html);
        $this->assertStringContainsString('Top-up months', $html);
        $this->assertStringContainsString('Topup', $html);
        $this->assertStringContainsString('TOTAL PAID', $html);
        $this->assertStringContainsString('PAID IN FULL', $html);

        $pdf = $this->receiptService()->renderPdf($payment);
        $this->assertNotEmpty($pdf);
        $this->assertStringStartsWith('%PDF', $pdf);
    }

    public function test_receipt_uses_historical_plan_price_not_current_plan_price(): void
    {
        $subscription = $this->subscribeAndActivateEssential($this->enigmaTech);
        $subscription = $subscription->fresh();

        // The payment was made when Essential cost $19.99/mo.
        $payment = $this->paymentService->createPending([
            'business_id' => $this->enigmaTech->id,
            'subscription_id' => $subscription->id,
            'user_id' => $this->alan->id,
            'amount' => 19.99,
            'currency' => 'USD',
            'method' => 'gateway',
            'payment_type' => 'subscription',
            'gateway_name' => 'pesapal',
            'gateway_transaction_id' => 'TXN-HIST-PRICE',
            'transaction_reference' => 'CUSTO-HIST-PRICE',
            'metadata' => [
                'billing_cycle' => 'monthly',
                'original_amount' => 19.99,
                'plan_price_monthly_usd' => 19.99,
                'plan_price_yearly_usd' => 199.9,
                'credit_used' => 0,
            ],
        ]);
        $payment = $this->paymentService->complete($payment, '{"status":"successful"}');

        // Later the plan price changes (as if the seeder/config was updated).
        $this->essential->update(['price_monthly_usd' => 24.99, 'price_yearly_usd' => 249.9]);

        $data = $this->receiptService()->buildData($payment->fresh());
        $html = view(PaymentReceiptService::RECEIPT_VIEW, $data)->render();

        // Receipt must show the price AT THE TIME OF PAYMENT ($19.99), not the
        // plan's current price ($24.99) - so a user regenerating an old receipt
        // sees what they actually paid.
        $this->assertEquals(19.99, (float) $data['monthlyRate']);
        $this->assertStringContainsString('$ 19.99', $html);
        $this->assertStringNotContainsString('$ 24.99', $html);
    }

    public function test_receipt_shows_referral_discount_and_credit_lines_from_payment_time(): void
    {
        $subscription = $this->subscribeAndActivateEssential($this->webFoundation);
        $subscription = $subscription->fresh();

        // Payment charged $14.99 after a $5 referral discount and a $0.50 credit
        // on a $20.49 original charge - all captured in metadata at initiate.
        $payment = $this->paymentService->createPending([
            'business_id' => $this->webFoundation->id,
            'subscription_id' => $subscription->id,
            'user_id' => $this->tim->id,
            'amount' => 14.99,
            'currency' => 'USD',
            'method' => 'gateway',
            'payment_type' => 'subscription',
            'gateway_name' => 'pesapal',
            'gateway_transaction_id' => 'TXN-DISCOUNT',
            'transaction_reference' => 'CUSTO-DISCOUNT',
            'metadata' => [
                'billing_cycle' => 'monthly',
                'original_amount' => 20.49,
                'plan_price_monthly_usd' => 20.49,
                'referral_discount_applied' => 5.0,
                'credit_used' => 0.5,
            ],
        ]);
        $payment = $this->paymentService->complete($payment, '{"status":"successful"}');

        $data = $this->receiptService()->buildData($payment->fresh());
        $html = view(PaymentReceiptService::RECEIPT_VIEW, $data)->render();

        // Both the referral discount and billing credit are itemized from the
        // payment-time metadata - never recomputed against current plan prices.
        $this->assertEquals(5.0, (float) $data['referralDiscountUsd']);
        $this->assertEquals(0.5, (float) $data['billingCreditUsd']);
        $this->assertStringContainsString('Referral discount', $html);
        $this->assertStringContainsString('-$ 5.00', $html);
        $this->assertStringContainsString('Billing credit applied', $html);
        $this->assertStringContainsString('-$ 0.50', $html);
        $this->assertStringContainsString('$ 14.99', $html);
        $this->assertStringContainsString('TOTAL PAID', $html);
    }

    public function test_receipt_plan_rate_always_denominated_in_usd(): void
    {
        $subscription = $this->subscribeAndActivateEssential($this->bellLabs);
        $subscription = $subscription->fresh();

        $payment = $this->paymentService->createPending([
            'business_id' => $this->bellLabs->id,
            'subscription_id' => $subscription->id,
            'user_id' => $this->dennis->id,
            'amount' => 74658.03,
            'currency' => 'UGX',
            'method' => 'gateway',
            'payment_type' => 'subscription',
            'gateway_name' => 'pesapal',
            'gateway_transaction_id' => 'TXN-RCPT-UGX',
            'transaction_reference' => 'CUSTO-RCPT-UGX',
            'metadata' => [
                'original_amount' => 20,
                'credit_used' => 0,
            ],
        ]);
        $payment = $this->paymentService->complete($payment, '{"status":"successful"}');

        $data = $this->receiptService()->buildData($payment);
        $html = view(PaymentReceiptService::RECEIPT_VIEW, $data)->render();

        // The plan rate derives from price_*_usd so it must read as USD even
        // when the customer paid in their own currency (UGX here).
        $amountSection = substr(
            $html,
            (int) strpos($html, 'Plan rate'),
            max(0, (int) strpos($html, 'TOTAL PAID') - (int) strpos($html, 'Plan rate')),
        );
        $this->assertStringContainsString('$', $amountSection, 'Plan rate must be shown in USD');
        $this->assertStringNotContainsString('UGX', $amountSection, 'Plan rate must NOT be shown in the payment currency');
        $this->assertStringContainsString('UGX 74,658.03', $html, 'TOTAL PAID stays in the payment currency');
    }

    public function test_receipt_pdf_download_response_streams_file(): void
    {
        $payment = $this->makeCompletedTopUpPayment();

        $response = $this->receiptService()->download($payment);

        $this->assertEquals(200, $response->getStatusCode());
        $disposition = $response->headers->get('Content-Disposition') ?? '';
        $this->assertStringContainsString('attachment', $disposition);
        $this->assertStringContainsString('.pdf', $disposition);
    }

    public function test_receipt_email_sends_pdf_attachment(): void
    {
        Mail::fake();
        $payment = $this->makeCompletedTopUpPayment();

        $sent = $this->receiptService()->email($payment);

        $this->assertTrue($sent);
        Mail::assertSent(StandardEmail::class, function (StandardEmail $mail) use ($payment) {
            $this->assertSame($this->grace->email, $mail->to[0]['address']);
            $this->assertCount(1, $mail->attachments());
            return true;
        });
    }

    public function test_receipt_email_uses_explicit_recipient_when_provided(): void
    {
        Mail::fake();
        $payment = $this->makeCompletedTopUpPayment();

        $sent = $this->receiptService()->email($payment, 'accounts@example.com');

        $this->assertTrue($sent);
        Mail::assertSent(StandardEmail::class, function (StandardEmail $mail) {
            $this->assertSame('accounts@example.com', $mail->to[0]['address']);
            return true;
        });
    }

    public function test_history_feed_merges_payments_changes_and_credits_newest_first(): void
    {
        $subscription = $this->subscribeAndActivateEssential($this->enigmaTech);
        $subscription = $subscription->fresh();

        // Top-up payment.
        $this->paymentService->createPending([
            'business_id' => $this->enigmaTech->id,
            'subscription_id' => $subscription->id,
            'amount' => 20.0,
            'currency' => 'USD',
            'method' => 'gateway',
            'payment_type' => 'topup',
            'metadata' => ['topup_months' => 1],
        ]);

        // Scheduled upgrade.
        $this->scheduledChangeService->schedulePlanChange($subscription->id, $this->professional->id, 'upgrade');

        $feed = app(BillingHistoryService::class)->feed($subscription->fresh());

        $this->assertNotEmpty($feed);
        $types = $feed->pluck('type')->unique()->values();

        $this->assertContains('payment', $types);
        $this->assertContains('change', $types);

        $timestamps = $feed->pluck('at')->map(fn ($t) => (string) $t)->values();
        $this->assertEquals($timestamps->sortByDesc(fn ($t) => $t)->values()->all(), $timestamps->all());

        $paymentItem = $feed->firstWhere('type', 'payment');
        $this->assertSame('Early renewal top-up', $paymentItem['event']);
        $this->assertSame(20.0, $paymentItem['amount']);
    }

    public function test_successful_payment_auto_sends_receipt_via_gateway_confirm(): void
    {
        Mail::fake();

        $subscription = $this->subscribeAndActivateEssential($this->apolloSoft);
        $subscription = $subscription->fresh();

        $payment = $this->paymentService->createPending([
            'business_id' => $this->apolloSoft->id,
            'subscription_id' => $subscription->id,
            'user_id' => $this->margaret->id,
            'amount' => 60.0,
            'currency' => 'USD',
            'method' => 'gateway',
            'payment_type' => 'topup',
            'gateway_name' => 'pesapal',
            'gateway_transaction_id' => 'mock-txn-auto-receipt',
            'metadata' => ['topup_months' => 3],
        ]);

        $this->mock(\App\Services\Payment\Gateways\PesaPalGateway::class, function ($mock) {
            $mock->shouldReceive('verify')->andReturn([
                'success' => true,
                'status' => 'successful',
                'transaction_id' => 'mock-txn-auto-receipt',
                'message' => 'Verified',
            ]);
        });

        $this->gatewayService->confirmPayment($payment->id);

        $payment->refresh();
        $this->assertTrue($payment->isCompleted());
        $this->assertNotNull($payment->receipt_sent_at);

        Mail::assertSent(StandardEmail::class, function (StandardEmail $mail) use ($payment) {
            $this->assertSame($this->margaret->email, $mail->to[0]['address']);
            $this->assertCount(1, $mail->attachments());
            return true;
        });
    }

    public function test_auto_receipt_skipped_for_zero_amount_payment(): void
    {
        Mail::fake();

        $subscription = $this->subscribeAndActivateEssential($this->webFoundation);
        $subscription = $subscription->fresh();

        $this->gatewayService->processZeroCostUpgrade($subscription, $this->professional->id, 'monthly');

        $payment = $this->paymentService->getByBusiness($this->webFoundation->id)->first();

        $this->assertNotNull($payment);
        $this->assertTrue($payment->isCompleted());
        $this->assertSame(0.0, (float) $payment->amount);
        $this->assertNull($payment->receipt_sent_at);

        Mail::assertNothingSent();
    }

    public function test_auto_receipt_does_not_resend_when_already_sent(): void
    {
        Mail::fake();

        $payment = $this->makeCompletedTopUpPayment();
        $payment->forceFill(['receipt_sent_at' => now()])->save();

        $sent = $this->receiptService()->sendReceiptIfDue($payment);

        $this->assertFalse($sent);
        $payment->refresh();
        $this->assertNotNull($payment->receipt_sent_at);

        Mail::assertNothingSent();
    }
}
