<?php

namespace App\Services\Payment;

use App\Mail\StandardEmail;
use App\Models\BillingPayment;
use App\Models\Business;
use App\Services\ReportExportService;
use App\Support\BrandLogo;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class PaymentReceiptService
{
    public const RECEIPT_VIEW = 'payments.subscription-receipt';

    public function __construct(
        private readonly ReportExportService $export,
    ) {}

    /**
     * Build the view data for a subscription payment receipt.
     *
     * @return array<string, mixed>
     */
    public function buildData(BillingPayment $payment): array
    {
        $subscription = $payment->subscription ?: $payment->subscription()->first();
        $business = $payment->business ?: $payment->business()->first();
        $plan = $subscription?->plan;

        $currency = strtoupper($payment->currency ?? 'USD');
        $billingCycle = $subscription?->billing_cycle ?? 'monthly';

        $metadata = $payment->metadata ?? [];

        // Use the price AT THE TIME OF PAYMENT (captured at initiate) so a
        // historical receipt shows what the user actually paid then, not the
        // plan's current (possibly changed) price. Falls back to the current
        // plan price only for payments made before this metadata existed.
        $planMonthlyUsd = (float) ($metadata['plan_price_monthly_usd']
            ?? $plan?->price_monthly_usd
            ?? 0);
        $planYearlyUsd = (float) ($metadata['plan_price_yearly_usd']
            ?? $plan?->price_yearly_usd
            ?? 0);

        $monthlyRate = $billingCycle === 'yearly'
            ? ($planYearlyUsd > 0 ? round($planYearlyUsd / 12, 4) : 0)
            : $planMonthlyUsd;

        $topUpMonths = isset($metadata['topup_months']) ? (int) $metadata['topup_months'] : null;

        return [
            'business' => $this->companyBrand(),
            'headerLogoDataUri' => BrandLogo::custosparkDataUri(),
            'reportTitleLogoDataUri' => BrandLogo::custosellDataUri(),
            'reportTitle' => 'Custosell Subscription Receipt',
            'reportPurpose' => null,
            'accent' => '#1e40af',
            'formatter' => $this->export,
            'payment' => $payment,
            'subscription' => $subscription,
            'subscriber' => $business ?? new Business(['name' => 'Custosell Subscriber']),
            'plan' => $plan,
            'currency' => $currency,
            'billingCycle' => $billingCycle,
            'monthlyRate' => $monthlyRate,
            'planMonthlyUsd' => $planMonthlyUsd,
            'planYearlyUsd' => $planYearlyUsd,
            'topUpMonths' => $topUpMonths,
            'originalAmountUsd' => (float) ($metadata['original_amount'] ?? $payment->amount),
            'amountPaid' => (float) $payment->amount,
        ];
    }

    public function renderPdf(BillingPayment $payment): string
    {
        return $this->export->renderPdfBytes(self::RECEIPT_VIEW, $this->buildData($payment));
    }

    /**
     * Stream a PDF download for the payment.
     *
     * @return mixed A Symfony/Illuminate download response.
     */
    public function download(BillingPayment $payment)
    {
        return $this->export->downloadPdf(
            self::RECEIPT_VIEW,
            $this->buildData($payment),
            $this->filename($payment),
        );
    }

    public function email(BillingPayment $payment, ?string $to = null): bool
    {
        $business = $payment->business ?: $payment->business()->first();
        $receiverEmail = $to
            ?? $payment->user?->email
            ?? $business?->email
            ?? $business?->owner?->email;

        if (!$receiverEmail) {
            Log::warning('[PaymentReceiptService] No recipient email for receipt', [
                'payment_id' => $payment->id,
            ]);
            return false;
        }

        try {
            $body = sprintf(
                'Hello,<br><br>Your Custosell subscription payment of <strong>%s</strong> has been received.'
                . ' Your payment receipt is attached.<br><br>Thank you for subscribing to Custosell.',
                $this->export->formatMoney((float) $payment->amount, strtoupper($payment->currency ?? 'USD')),
            );

            Mail::to($receiverEmail)->send(new StandardEmail(
                title: 'Custosell Subscription Payment Receipt',
                mailBody: $body,
                isHtml: true,
                fileAttachments: [
                    ['data' => $this->renderPdf($payment), 'name' => $this->filename($payment), 'mime' => 'application/pdf'],
                ]
            ));

            return true;
        } catch (\Throwable $e) {
            Log::error('[PaymentReceiptService] Receipt email failed', [
                'payment_id' => $payment->id,
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }

    public function filename(BillingPayment $payment): string
    {
        $ref = $payment->transaction_reference ?? ('payment-' . $payment->id);
        return 'receipt-' . preg_replace('/[^A-Za-z0-9_-]/', '-', $ref);
    }

    /**
     * Send a receipt immediately for a completed, paid payment. Guards against
     * duplicate sends and skips zero-amount payments (free onboarding, zero-cost
     * upgrades). Returns false when there is nothing to send or sending failed.
     */
    public function sendReceiptIfDue(BillingPayment $payment): bool
    {
        if (!$payment->isCompleted()) {
            return false;
        }

        if ((float) $payment->amount <= 0) {
            return false;
        }

        if ($payment->receipt_sent_at !== null) {
            return false;
        }

        $sent = $this->email($payment);

        if ($sent) {
            $payment->forceFill(['receipt_sent_at' => now()])->save();
        }

        return $sent;
    }

    /**
     * A branded "company" object used as the top-of-receipt merchant header,
     * so the receipt announces Custospark Company Ltd as the seller.
     */
    private function companyBrand(): object
    {
        return (object) [
            'name' => config('brand.company_name', 'Custospark Company Ltd'),
            'address' => null,
            'city' => config('brand.company_city', 'Kampala'),
            'state' => '',
            'country' => config('brand.company_country', 'Uganda'),
            'phone' => config('brand.company_phone', ''),
            'email' => config('brand.company_email', 'support@custosell.com'),
            'website' => config('brand.url', 'https://www.custosell.com'),
            'tax_id' => null,
            'receipt_footer' => null,
            'contact_email' => 'info@custospark.com',
            'contact_website' => 'www.custospark.com',
        ];
    }
}