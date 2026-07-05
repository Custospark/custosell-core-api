<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Business;
use App\Models\Invoice;

class InvoicePdfBuilder
{
    public function __construct(
        protected ReportExportService $export,
    ) {}

    /**
     * @return array{view: string, data: array<string, mixed>, filename: string, orientation: string}
     */
    public function build(Invoice $invoice, Business $business): array
    {
        $invoice->loadMissing(['items.product', 'customer', 'createdBy']);

        $currency = $business->currency ?? 'UGX';
        $balanceDue = max(0, (float) $invoice->total_amount - (float) $invoice->amount_paid);

        $statusLabel = match ($invoice->status) {
            'partially_paid' => 'Partially Paid',
            'cancelled' => 'Cancelled',
            default => ucfirst((string) $invoice->status),
        };

        $filename = $this->export->buildFilename($business, 'invoice-' . $invoice->invoice_number);

        return [
            'view' => 'invoices.pdf',
            'data' => [
                'business' => $business,
                'invoice' => $invoice,
                'formatter' => $this->export,
                'currency' => $currency,
                'balanceDue' => $balanceDue,
                'statusLabel' => $statusLabel,
                'reportTitle' => 'Tax Invoice',
                'reportSubtitle' => sprintf(
                    'Invoice #%s · Issued %s · Due %s',
                    $invoice->invoice_number,
                    $invoice->issue_date?->format('M d, Y'),
                    $invoice->due_date?->format('M d, Y'),
                ),
                'reportPurpose' => $balanceDue > 0
                    ? 'Please remit payment by the due date shown above.'
                    : 'This invoice has been fully paid. Thank you for your business.',
                'accent' => '#1e40af',
                'summaryCards' => [
                    ['label' => 'Invoice #', 'value' => $invoice->invoice_number],
                    ['label' => 'Status', 'value' => $statusLabel],
                    ['label' => 'Total', 'value' => $this->export->formatMoney((float) $invoice->total_amount, $currency)],
                    [
                        'label' => 'Balance Due',
                        'value' => $this->export->formatMoney($balanceDue, $currency),
                        'tone' => $balanceDue > 0 ? 'negative' : 'positive',
                    ],
                ],
            ],
            'filename' => $filename,
            'orientation' => 'portrait',
        ];
    }
}
