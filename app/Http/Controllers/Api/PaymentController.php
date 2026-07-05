<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\PaymentResource;
use App\Models\Payment;
use App\Services\ReportExportService;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class PaymentController extends Controller
{
    public function __construct(
        protected ReportExportService $export,
    ) {}

    public function show(Request $request, int $id): PaymentResource
    {
        $payment = Payment::with(['recordedBy', 'payable'])
            ->where('business_id', $request->user()->business_id)
            ->findOrFail($id);

        return new PaymentResource($payment);
    }

    public function downloadReceiptPdf(Request $request, int $id): Response
    {
        $payment = Payment::with(['recordedBy', 'payable'])
            ->where('business_id', $request->user()->business_id)
            ->findOrFail($id);

        $business = $request->user()->business;
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
        $paymentService = app(\App\Services\PaymentService::class);
        $totalBill = $paymentService->netBillAmount($payable);
        $totalPaid = (float) ($payable->amount_paid ?? 0);
        $previousPaid = max(0, $totalPaid - (float) $payment->amount);
        $receiptDetails = \App\Services\PaymentReceiptDataBuilder::buildForPayable($payable, $payment->payable_type);

        $filename = $this->export->buildFilename($business, 'receipt-' . $payment->receipt_number);

        $pdfData = [
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
        ];

        return $this->export->downloadPdf('payments.receipt', $pdfData, $filename, 'portrait');
    }
}
