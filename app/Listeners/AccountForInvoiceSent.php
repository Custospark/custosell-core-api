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
            $invoice = $event->invoice->loadMissing('customer');
            $businessId = $invoice->business_id;

            if ($this->journalEntryService->getEntryByReference('invoice', $invoice->id, $businessId)) {
                return;
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

            $lines[] = [
                'account_code' => $codes['sales_revenue'],
                'debit' => 0,
                'credit' => $subtotal,
                'description' => "Invoice {$invoice->invoice_number} - revenue",
            ];

            if ($taxTotal > 0) {
                $lines[] = [
                    'account_code' => $codes['vat_payable'],
                    'debit' => 0,
                    'credit' => $taxTotal,
                    'description' => "Invoice {$invoice->invoice_number} - VAT",
                ];
            }

            $cogsTotal = $this->calculateCOGS($invoice);
            if ($cogsTotal > 0) {
                $lines[] = [
                    'account_code' => $codes['cogs'],
                    'debit' => $cogsTotal,
                    'credit' => 0,
                    'description' => "Invoice {$invoice->invoice_number} - COGS",
                ];
                $lines[] = [
                    'account_code' => $codes['inventory'],
                    'debit' => 0,
                    'credit' => $cogsTotal,
                    'description' => "Invoice {$invoice->invoice_number} - inventory reduction",
                ];
            }

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
                'cogs' => $cogsTotal ?? 0,
            ]);
        } catch (\Throwable $e) {
            Log::error("Accounting automation failed for invoice sent {$event->invoice->id}: {$e->getMessage()}", [
                'invoice_id' => $event->invoice->id,
                'exception' => $e,
            ]);
        }
    }

    protected function calculateCOGS(Invoice $invoice): float
    {
        $invoice->loadMissing('items.product');
        $total = 0.0;

        foreach ($invoice->items as $item) {
            $product = $item->product;
            if ($product && (float) $product->cost_price > 0) {
                $total += (float) $product->cost_price * (float) $item->quantity;
            }
        }

        return $total;
    }
}
