<?php

namespace App\Listeners;

use App\Events\InvoicePaidForAccounting;
use App\Services\JournalEntryService;
use Illuminate\Support\Facades\Log;

class AccountForInvoicePaid
{
    public function __construct(
        protected JournalEntryService $journalEntryService,
    ) {}

    public function handle(InvoicePaidForAccounting $event): void
    {
        try {
            $invoice = $event->invoice->loadMissing('items.product');
            $businessId = $invoice->business_id;
            $amount = $event->amount;

            $originalEntry = $this->journalEntryService->getEntryByReference('invoice', $invoice->id, $businessId);

            if (!$originalEntry) {
                Log::warning("No original invoice journal entry found for payment", [
                    'invoice_id' => $invoice->id,
                    'invoice_number' => $invoice->invoice_number,
                ]);
                return;
            }

            $codes = config('accounting.default_account_codes');
            $date = now()->toDateString();

            $lines = [];

            $lines[] = [
                'account_code' => $codes['cash'],
                'debit' => $amount,
                'credit' => 0,
                'description' => "Payment received for Invoice {$invoice->invoice_number}",
            ];

            $lines[] = [
                'account_code' => $codes['accounts_receivable'],
                'debit' => 0,
                'credit' => $amount,
                'description' => "Payment received for Invoice {$invoice->invoice_number}",
            ];

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
                "Payment received for Invoice {$invoice->invoice_number}",
                $lines,
                'invoice_payment',
                $invoice->id,
                $invoice->created_by,
            );

            Log::info("Accounting automation: Invoice payment entry posted", [
                'invoice_id' => $invoice->id,
                'invoice_number' => $invoice->invoice_number,
                'amount' => $amount,
                'cogs' => $cogsTotal,
            ]);
        } catch (\Throwable $e) {
            Log::error("Accounting automation failed for invoice payment {$event->invoice->id}: {$e->getMessage()}", [
                'invoice_id' => $event->invoice->id,
                'exception' => $e,
            ]);
        }
    }

    protected function calculateCOGS($invoice): float
    {
        $total = 0;

        foreach ($invoice->items as $item) {
            $product = $item->product;
            if ($product && (float) $product->cost_price > 0) {
                $total += (float) $product->cost_price * (int) $item->quantity;
            }
        }

        return $total;
    }
}
