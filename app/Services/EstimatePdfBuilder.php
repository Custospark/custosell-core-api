<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Business;
use App\Models\Estimate;

class EstimatePdfBuilder
{
    public function __construct(
        protected ReportExportService $export,
    ) {}

    /**
     * @return array{view: string, data: array<string, mixed>, filename: string, orientation: string}
     */
    public function build(Estimate $estimate, Business $business): array
    {
        $estimate->loadMissing(['lineItems.product', 'customer', 'createdBy']);

        $currency = $estimate->currency ?? $business->currency ?? 'UGX';

        $statusLabel = match ($estimate->status) {
            'converted' => 'Converted',
            default => ucfirst((string) $estimate->status),
        };

        $filename = $this->export->buildFilename($business, 'estimate-' . $estimate->estimate_number);

        return [
            'view' => 'estimates.pdf',
            'data' => [
                'business' => $business,
                'estimate' => $estimate,
                'formatter' => $this->export,
                'currency' => $currency,
                'statusLabel' => $statusLabel,
                'reportTitle' => 'Estimate / Proposal',
                'reportSubtitle' => sprintf(
                    'Estimate #%s · Version %d%s',
                    $estimate->estimate_number,
                    $estimate->version,
                    $estimate->valid_until ? ' · Valid until ' . $estimate->valid_until->format('M d, Y') : '',
                ),
                'reportPurpose' => $estimate->status === 'approved'
                    ? 'This estimate has been approved. Thank you for your business.'
                    : 'Please review this estimate and let us know if you have any questions.',
                'accent' => '#0f766e',
                'summaryCards' => [
                    ['label' => 'Estimate #', 'value' => $estimate->estimate_number],
                    ['label' => 'Status', 'value' => $statusLabel],
                    ['label' => 'Total', 'value' => $this->export->formatMoney((float) $estimate->total, $currency)],
                ],
            ],
            'filename' => $filename,
            'orientation' => 'portrait',
        ];
    }
}
