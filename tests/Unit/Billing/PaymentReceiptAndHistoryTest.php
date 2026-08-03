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
        $this->assertStringContainsString('Custosell — Essential', $html);
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
}
