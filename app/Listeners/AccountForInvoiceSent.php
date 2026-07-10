<?php

namespace App\Listeners;

use App\Events\InvoiceSentForAccounting;
use App\Models\Invoice;
use App\Services\JournalEntryService;
use Illuminate\Support\Facades\Log;

class AccountForInvoiceSent
{
    public function __construct(
        protected JournalEntryService $journalEntryService,
    ) {}

    public function handle(InvoiceSentForAccounting $event): void
    {
        try {
            $invoice = $event->invoice->loadMissing(['customer', 'items.product']);
            $businessId = $invoice->business_id;

            if ($this->journalEntryService->getEntryByReference('invoice', $invoice->id, $businessId)) {
                return;
            }

            if ($invoice->sale_id) {
                $saleEntry = $this->journalEntryService->getEntryByReference('sale', $invoice->sale_id, $businessId);
                if ($saleEntry) {
                    Log::info('Accounting automation: Invoice send skipped — revenue already posted on linked sale', [
                        'invoice_id' => $invoice->id,
                        'sale_id' => $invoice->sale_id,
                    ]);

                    return;
                }
            }

            $codes = config('accounting.default_account_codes');
            $date = $invoice->issue_date instanceof \Carbon\Carbon
                ? $invoice->issue_date->toDateString()
                : now()->toDateString();

            $totalAmount = (float) $invoice->total_amount;
            $subtotal = (float) $invoice->subtotal;
            $taxTotal = (float) $invoice->tax_total;
            $customerName = $invoice->customer?->name ?? 'Unknown Customer';

            $lines = [];

            $lines[] = [
                'account_code' => $codes['accounts_receivable'],
                'debit' => $totalAmount,
                'credit' => 0,
                'description' => "Invoice {$invoice->invoice_number} sent to {$customerName}",
            ];

            $this->appendInvoiceRevenueLines($lines, $invoice, $codes, $subtotal);

            if ($taxTotal > 0) {
                $lines[] = [
                    'account_code' => $codes['vat_payable'],
                    'debit' => 0,
                    'credit' => $taxTotal,
                    'description' => "Invoice {$invoice->invoice_number} - VAT",
                ];
            }

            $cogsTotal = 0;

            $this->journalEntryService->createAndPostEntry(
                $businessId,
                $date,
                "Invoice {$invoice->invoice_number} sent to {$customerName}",
                $lines,
                'invoice',
                $invoice->id,
                $invoice->created_by,
            );

            Log::info("Accounting automation: Invoice sent entry posted", [
                'invoice_id' => $invoice->id,
                'invoice_number' => $invoice->invoice_number,
                'total_amount' => $totalAmount,
                'cogs' => $cogsTotal,
            ]);
        } catch (\Throwable $e) {
            Log::error("Accounting automation failed for invoice sent {$event->invoice->id}: {$e->getMessage()}", [
                'invoice_id' => $event->invoice->id,
                'exception' => $e,
            ]);
        }
    }

    /**
     * Split invoice revenue: product lines → 4100, service / description-only → 4200.
     *
     * @param  array<int, array<string, mixed>>  $lines
     * @param  array<string, string>  $codes
     */
    protected function appendInvoiceRevenueLines(array &$lines, Invoice $invoice, array $codes, float $subtotal): void
    {
        $productSum = 0.0;
        $serviceSum = 0.0;

        foreach ($invoice->items as $item) {
            $amount = (float) $item->subtotal;
            if (!$item->product_id) {
                $serviceSum += $amount;
                continue;
            }
            if ($item->product && $item->product->isService()) {
                $serviceSum += $amount;
            } else {
                $productSum += $amount;
            }
        }

        $lineSum = $productSum + $serviceSum;
        if ($lineSum <= 0) {
            $lines[] = [
                'account_code' => $codes['sales_revenue'],
                'debit' => 0,
                'credit' => $subtotal,
                'description' => "Invoice {$invoice->invoice_number} - revenue",
            ];

            return;
        }

        if (abs($lineSum - $subtotal) > 0.009) {
            $scale = $subtotal / $lineSum;
            $productSum = round($productSum * $scale, 2);
            $serviceSum = round($subtotal - $productSum, 2);
        } else {
            $productSum = round($productSum, 2);
            $serviceSum = round($subtotal - $productSum, 2);
        }

        if ($productSum > 0) {
            $lines[] = [
                'account_code' => $codes['sales_revenue'],
                'debit' => 0,
                'credit' => $productSum,
                'description' => "Invoice {$invoice->invoice_number} - product revenue",
            ];
        }

        if ($serviceSum > 0) {
            $lines[] = [
                'account_code' => $codes['service_revenue'] ?? '4200',
                'debit' => 0,
                'credit' => $serviceSum,
                'description' => "Invoice {$invoice->invoice_number} - service revenue",
            ];
        }
    }
}
