<?php

declare(strict_types=1);

namespace App\Services;

use App\Mail\CustomerDocumentEmail;
use App\Models\Business;
use App\Models\Customer;
use App\Models\Estimate;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Sale;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

class CustomerDocumentEmailService
{
    public function __construct(
        protected ReportExportService $export,
        protected InvoicePdfBuilder $invoicePdfBuilder,
        protected EstimatePdfBuilder $estimatePdfBuilder,
        protected PaymentReceiptPdfBuilder $paymentReceiptPdfBuilder,
        protected SalePdfBuilder $salePdfBuilder,
    ) {}

    /**
     * @return array{sent_to: string, sent_at: string, document_type: string, document_ref: string}
     */
    public function sendInvoice(Invoice $invoice, Business $business, string $to, ?string $customMessage = null): array
    {
        $invoice->loadMissing(['customer']);
        $this->assertValidRecipient($to);

        $customerName = $invoice->customer?->name ?? 'Customer';
        $businessName = $business->name ?: 'Your business';
        $pdfConfig = $this->invoicePdfBuilder->build($invoice, $business);
        $pdfBytes = $this->export->renderPdfBytes(
            $pdfConfig['view'],
            $pdfConfig['data'],
            $pdfConfig['orientation'],
        );

        $subject = sprintf('Invoice %s from %s', $invoice->invoice_number, $businessName);
        $title = sprintf('Invoice %s', $invoice->invoice_number);
        $body = $this->buildInvoiceBody($customerName, $businessName, $invoice->invoice_number, $customMessage);

        $this->dispatch(
            to: $to,
            subject: $subject,
            title: $title,
            body: $body,
            business: $business,
            attachmentName: $pdfConfig['filename'] . '.pdf',
            attachmentBytes: $pdfBytes,
        );

        $this->recordInvoiceEmailSent($invoice);

        return $this->buildSendResult($to, 'invoice', $invoice->invoice_number, $invoice);
    }

    /**
     * @return array{sent_to: string, sent_at: string, document_type: string, document_ref: string}
     */
    public function sendEstimate(Estimate $estimate, Business $business, string $to, ?string $customMessage = null): array
    {
        $estimate->loadMissing(['customer']);
        $this->assertValidRecipient($to);

        $customerName = $estimate->customer?->name ?? 'Customer';
        $businessName = $business->name ?: 'Your business';
        $pdfConfig = $this->estimatePdfBuilder->build($estimate, $business);
        $pdfBytes = $this->export->renderPdfBytes(
            $pdfConfig['view'],
            $pdfConfig['data'],
            $pdfConfig['orientation'],
        );

        $subject = sprintf('Estimate %s from %s', $estimate->estimate_number, $businessName);
        $title = sprintf('Estimate %s', $estimate->estimate_number);
        $body = $this->buildEstimateBody($customerName, $businessName, $estimate->estimate_number, $customMessage);

        $this->dispatch(
            to: $to,
            subject: $subject,
            title: $title,
            body: $body,
            business: $business,
            attachmentName: $pdfConfig['filename'] . '.pdf',
            attachmentBytes: $pdfBytes,
        );

        $this->recordEstimateEmailSent($estimate);

        return $this->buildSendResult($to, 'estimate', $estimate->estimate_number, $estimate);
    }

    /**
     * @return array{sent_to: string, sent_at: string, document_type: string, document_ref: string}
     */
    public function sendPaymentReceipt(Payment $payment, Business $business, string $to, ?string $customMessage = null): array
    {
        $this->assertValidRecipient($to);

        $customerName = $this->resolvePaymentCustomerName($payment);
        $businessName = $business->name ?: 'Your business';
        $pdfConfig = $this->paymentReceiptPdfBuilder->build($payment, $business);
        $pdfBytes = $this->export->renderPdfBytes(
            $pdfConfig['view'],
            $pdfConfig['data'],
            $pdfConfig['orientation'],
        );

        $subject = sprintf('Payment receipt %s from %s', $payment->receipt_number, $businessName);
        $title = sprintf('Payment receipt %s', $payment->receipt_number);
        $body = $this->buildReceiptBody($customerName, $businessName, $payment->receipt_number, $customMessage);

        $this->dispatch(
            to: $to,
            subject: $subject,
            title: $title,
            body: $body,
            business: $business,
            attachmentName: $pdfConfig['filename'] . '.pdf',
            attachmentBytes: $pdfBytes,
        );

        $this->recordPaymentEmailSent($payment);

        return $this->buildSendResult($to, 'payment_receipt', $payment->receipt_number, $payment);
    }

    public function sendSaleReceipt(Sale $sale, Business $business, string $to, ?string $customMessage = null): array
    {
        $sale->loadMissing(['customer', 'saleItems', 'user', 'payments']);
        $this->assertValidRecipient($to);

        $customerName = $sale->customer?->name ?? 'Customer';
        $businessName = $business->name ?: 'Your business';
        $pdfConfig = $this->salePdfBuilder->build($sale, $business);
        $pdfBytes = $this->export->renderPdfBytes(
            $pdfConfig['view'],
            $pdfConfig['data'],
            $pdfConfig['orientation'],
        );

        $subject = sprintf('Receipt %s from %s', $sale->receipt_number, $businessName);
        $title = sprintf('Sales Receipt %s', $sale->receipt_number);
        $body = $this->buildSaleReceiptBody($customerName, $businessName, $sale->receipt_number, $customMessage);

        $this->dispatch(
            to: $to,
            subject: $subject,
            title: $title,
            body: $body,
            business: $business,
            attachmentName: $pdfConfig['filename'] . '.pdf',
            attachmentBytes: $pdfBytes,
        );

        $this->recordSaleEmailSent($sale);

        return $this->buildSendResult($to, 'sale_receipt', $sale->receipt_number, $sale);
    }

    public function resolveCustomerEmail(?Customer $customer): ?string
    {
        $email = trim((string) ($customer?->email ?? ''));

        return filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : null;
    }

    private function assertValidRecipient(string $to): void
    {
        if (! filter_var(trim($to), FILTER_VALIDATE_EMAIL)) {
            throw new \InvalidArgumentException('A valid recipient email address is required.');
        }
    }

    private function buildInvoiceBody(
        string $customerName,
        string $businessName,
        string $invoiceNumber,
        ?string $customMessage,
    ): string {
        $parts = [
            '<p>Dear ' . e($customerName) . ',</p>',
            '<p>Please find attached invoice <strong>' . e($invoiceNumber) . '</strong> from <strong>' . e($businessName) . '</strong>.</p>',
        ];

        if ($customMessage !== null && trim($customMessage) !== '') {
            $parts[] = '<p>' . nl2br(e(trim($customMessage))) . '</p>';
        }

        $parts[] = '<p>If you have any questions about this invoice, please reply to this email and ' . e($businessName) . ' will get back to you.</p>';
        $parts[] = '<p style="color:#64748b;font-size:14px;margin-top:1.5em;">This message was sent to you on behalf of <strong>' . e($businessName) . '</strong> via Custosell.</p>';

        return implode("\n", $parts);
    }

    private function buildEstimateBody(
        string $customerName,
        string $businessName,
        string $estimateNumber,
        ?string $customMessage,
    ): string {
        $parts = [
            '<p>Dear ' . e($customerName) . ',</p>',
            '<p>Please find attached estimate <strong>' . e($estimateNumber) . '</strong> from <strong>' . e($businessName) . '</strong>.</p>',
        ];

        if ($customMessage !== null && trim($customMessage) !== '') {
            $parts[] = '<p>' . nl2br(e(trim($customMessage))) . '</p>';
        }

        $parts[] = '<p>If you have any questions about this estimate, please reply to this email and ' . e($businessName) . ' will get back to you.</p>';
        $parts[] = '<p style="color:#64748b;font-size:14px;margin-top:1.5em;">This message was sent to you on behalf of <strong>' . e($businessName) . '</strong> via Custosell.</p>';

        return implode("\n", $parts);
    }

    private function buildReceiptBody(
        string $customerName,
        string $businessName,
        string $receiptNumber,
        ?string $customMessage,
    ): string {
        $parts = [
            '<p>Dear ' . e($customerName) . ',</p>',
            '<p>Thank you for your payment. Please find your payment receipt <strong>' . e($receiptNumber) . '</strong> attached.</p>',
        ];

        if ($customMessage !== null && trim($customMessage) !== '') {
            $parts[] = '<p>' . nl2br(e(trim($customMessage))) . '</p>';
        }

        $parts[] = '<p>We appreciate your business. If you need anything else, please reply to this email.</p>';
        $parts[] = '<p style="color:#64748b;font-size:14px;margin-top:1.5em;">This message was sent to you on behalf of <strong>' . e($businessName) . '</strong> via Custosell.</p>';

        return implode("\n", $parts);
    }

    private function buildSaleReceiptBody(
        string $customerName,
        string $businessName,
        string $receiptNumber,
        ?string $customMessage,
    ): string {
        $parts = [
            '<p>Dear ' . e($customerName) . ',</p>',
            '<p>Thank you for your purchase. Please find your sales receipt <strong>' . e($receiptNumber) . '</strong> attached.</p>',
        ];

        if ($customMessage !== null && trim($customMessage) !== '') {
            $parts[] = '<p>' . nl2br(e(trim($customMessage))) . '</p>';
        }

        $parts[] = '<p>We appreciate your business. If you need anything else, please reply to this email.</p>';
        $parts[] = '<p style="color:#64748b;font-size:14px;margin-top:1.5em;">This message was sent to you on behalf of <strong>' . e($businessName) . '</strong> via Custosell.</p>';

        return implode("\n", $parts);
    }

    private function resolvePaymentCustomerName(Payment $payment): string
    {
        $payable = $payment->payable;
        if ($payable === null) {
            return 'Customer';
        }

        if ($payment->payable_type === 'invoice') {
            return $payable->customer?->name ?? 'Customer';
        }

        return $payable->customer?->name ?? 'Customer';
    }

    private function dispatch(
        string $to,
        string $subject,
        string $title,
        string $body,
        Business $business,
        string $attachmentName,
        string $attachmentBytes,
    ): void {
        $replyTo = $this->resolveBusinessReplyTo($business);

        try {
            Mail::to($to)->send(new CustomerDocumentEmail(
                subjectLine: $subject,
                title: $title,
                mailBody: $body,
                businessName: $business->name ?: 'Your business',
                replyToEmail: $replyTo,
                fileAttachments: [[
                    'data' => $attachmentBytes,
                    'name' => $this->sanitizeAttachmentName($attachmentName),
                    'mime' => 'application/pdf',
                ]],
            ));
        } catch (\Throwable $e) {
            Log::error('Customer document email failed', [
                'to' => $to,
                'subject' => $subject,
                'error' => $e->getMessage(),
            ]);

            throw new \RuntimeException('Could not send email. Please try again or check your mail configuration.');
        }
    }

    private function resolveBusinessReplyTo(Business $business): ?string
    {
        foreach ([$business->business_email ?? null, $business->email ?? null] as $candidate) {
            $email = trim((string) $candidate);
            if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL)) {
                return $email;
            }
        }

        return null;
    }

    private function sanitizeAttachmentName(string $filename): string
    {
        $filename = Str::replace(['/', '\\', ':', '*', '?', '"', '<', '>', '|'], '-', $filename);

        return $filename !== '' ? $filename : 'document.pdf';
    }

    private function recordInvoiceEmailSent(Invoice $invoice): void
    {
        $invoice->update([
            'email_sent_count' => (int) $invoice->email_sent_count + 1,
            'last_emailed_at' => now(),
        ]);
        $invoice->refresh();
    }

    private function recordEstimateEmailSent(Estimate $estimate): void
    {
        $estimate->update([
            'email_sent_count' => (int) $estimate->email_sent_count + 1,
            'last_emailed_at' => now(),
        ]);
        $estimate->refresh();
    }

    private function recordPaymentEmailSent(Payment $payment): void
    {
        $payment->update([
            'email_sent_count' => (int) $payment->email_sent_count + 1,
            'last_emailed_at' => now(),
        ]);
        $payment->refresh();
    }

    private function recordSaleEmailSent(Sale $sale): void
    {
        $sale->update([
            'email_sent_count' => (int) $sale->email_sent_count + 1,
            'last_emailed_at' => now(),
        ]);
        $sale->refresh();
    }

    /**
     * @return array{
     *   sent_to: string,
     *   sent_at: string,
     *   document_type: string,
     *   document_ref: string,
     *   email_sent_count: int,
     *   last_emailed_at: string|null
     * }
     */
    private function buildSendResult(string $to, string $documentType, string $documentRef, Invoice|Payment|Estimate|Sale $document): array
    {
        return [
            'sent_to' => $to,
            'sent_at' => now()->toIso8601String(),
            'document_type' => $documentType,
            'document_ref' => $documentRef,
            'email_sent_count' => (int) $document->email_sent_count,
            'last_emailed_at' => $document->last_emailed_at?->toIso8601String(),
        ];
    }
}
