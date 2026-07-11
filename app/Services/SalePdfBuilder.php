<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Business;
use App\Models\Sale;

class SalePdfBuilder
{
    public function __construct(
        protected ReportExportService $export,
    ) {}

    /**
     * @return array{view: string, data: array<string, mixed>, filename: string, orientation: string}
     */
    public function build(Sale $sale, Business $business): array
    {
        $sale->loadMissing(['saleItems', 'customer', 'user', 'payments']);

        $currency = $business->currency ?? 'UGX';
        $totalRefunded = $sale->saleItems->sum(fn ($i) => (float) $i->refunded_amount);
        $netAmount = max(0, (float) $sale->total_amount - $totalRefunded);
        $amountPaid = (float) ($sale->amount_paid ?? $sale->total_amount);
        $balanceDue = max(0, $netAmount - $amountPaid);

        $statusLabel = match ($sale->payment_status) {
            'partially_paid' => 'Partially Paid',
            'partially_refunded' => 'Partially Refunded',
            'refunded' => 'Refunded',
            default => 'Paid',
        };

        $filename = $this->export->buildFilename($business, 'receipt-' . $sale->receipt_number);

        return [
            'view' => 'sales.pdf',
            'data' => [
                'business' => $business,
                'sale' => $sale,
                'formatter' => $this->export,
                'currency' => $currency,
                'balanceDue' => $balanceDue,
                'netAmount' => $netAmount,
                'totalRefunded' => $totalRefunded,
                'statusLabel' => $statusLabel,
                'reportTitle' => 'Sales Receipt',
                'reportSubtitle' => sprintf(
                    'Receipt #%s · %s',
                    $sale->receipt_number,
                    $sale->created_at?->format('M d, Y H:i'),
                ),
                'reportPurpose' => 'Thank you for your purchase.',
                'accent' => '#059669',
                'summaryCards' => [
                    ['label' => 'Receipt #', 'value' => $sale->receipt_number],
                    ['label' => 'Status', 'value' => $statusLabel],
                    ['label' => 'Total', 'value' => $this->export->formatMoney((float) $sale->total_amount, $currency)],
                    [
                        'label' => 'Amount Paid',
                        'value' => $this->export->formatMoney($amountPaid, $currency),
                        'tone' => $balanceDue > 0 ? 'negative' : 'positive',
                    ],
                ],
            ],
            'filename' => $filename,
            'orientation' => 'portrait',
        ];
    }
}
