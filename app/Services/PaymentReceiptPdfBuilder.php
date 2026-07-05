<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Business;
use App\Models\Payment;

class PaymentReceiptPdfBuilder
{
    public function __construct(
        protected ReportExportService $export,
        protected PaymentService $paymentService,
    ) {}

    /**
     * @return array{view: string, data: array<string, mixed>, filename: string, orientation: string}
     */
    public function build(Payment $payment, Business $business): array
    {
        $payment->loadMissing(['recordedBy', 'payable']);
        $payable = $payment->payable;

        if ($payment->payable_type === 'invoice') {
            $payable?->load(['items', 'customer']);
        } else {
            $payable?->load(['saleItems', 'customer']);
        }

        $currency = $business->currency ?? 'UGX';

        $referenceLabel = $payment->payable_type === 'invoice'
            ? ($payable->invoice_number ?? 'Invoice')
            : ($payable->receipt_number ?? 'Sale');

        $referenceType = $payment->payable_type === 'invoice' ? 'Invoice' : 'Sale';
        $totalBill = $this->paymentService->netBillAmount($payable);
        $totalPaid = (float) ($payable->amount_paid ?? 0);
        $previousPaid = max(0, $totalPaid - (float) $payment->amount);
        $receiptDetails = PaymentReceiptDataBuilder::buildForPayable($payable, $payment->payable_type);

        $filename = $this->export->buildFilename($business, 'receipt-' . $payment->receipt_number);

        return [
            'view' => 'payments.receipt',
            'data' => [
                'business' => $business,
                'payment' => $payment,
                'payable' => $payable,
                'formatter' => $this->export,
                'currency' => $currency,
                'referenceLabel' => $referenceLabel,
                'referenceType' => $referenceType,
                'totalBill' => $totalBill,
                'previousPaid' => $previousPaid,
                'totalPaid' => $totalPaid,
                'balanceAfter' => (float) $payment->balance_after,
                'lineItems' => $receiptDetails['lineItems'],
                'subtotal' => $receiptDetails['subtotal'],
                'discount' => $receiptDetails['discount'],
                'taxTotal' => $receiptDetails['tax_total'],
                'totalRefunded' => $receiptDetails['total_refunded'],
                'billTotal' => $receiptDetails['bill_total'],
                'customerName' => $receiptDetails['customer_name'],
                'reportTitle' => 'Payment Receipt',
                'reportSubtitle' => sprintf(
                    'Receipt #%s · %s %s',
                    $payment->receipt_number,
                    $referenceType,
                    $referenceLabel,
                ),
                'reportPurpose' => (float) $payment->balance_after > 0
                    ? 'This is a partial payment. Balance remaining is shown below.'
                    : 'This bill is now fully paid. Thank you for your business.',
                'accent' => '#047857',
                'summaryCards' => [
                    ['label' => 'Receipt #', 'value' => $payment->receipt_number],
                    ['label' => 'This Payment', 'value' => $this->export->formatMoney((float) $payment->amount, $currency), 'tone' => 'positive'],
                    ['label' => 'Total Paid', 'value' => $this->export->formatMoney($totalPaid, $currency)],
                    [
                        'label' => 'Balance Due',
                        'value' => $this->export->formatMoney((float) $payment->balance_after, $currency),
                        'tone' => (float) $payment->balance_after > 0 ? 'negative' : 'positive',
                    ],
                ],
            ],
            'filename' => $filename,
            'orientation' => 'portrait',
        ];
    }
}
