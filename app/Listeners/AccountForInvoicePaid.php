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
            $invoice = $event->invoice;
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
            $paymentAccountCode = $this->resolvePaymentAccountCode($event->paymentMethod, $codes);

            $lines = [
                [
                    'account_code' => $paymentAccountCode,
                    'debit' => $amount,
                    'credit' => 0,
                    'description' => "Payment received for Invoice {$invoice->invoice_number}",
                ],
                [
                    'account_code' => $codes['accounts_receivable'],
                    'debit' => 0,
                    'credit' => $amount,
                    'description' => "Payment received for Invoice {$invoice->invoice_number}",
                ],
            ];

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
                'payment_method' => $event->paymentMethod,
                'payment_account' => $paymentAccountCode,
            ]);
        } catch (\Throwable $e) {
            Log::error("Accounting automation failed for invoice payment {$event->invoice->id}: {$e->getMessage()}", [
                'invoice_id' => $event->invoice->id,
                'exception' => $e,
            ]);
        }
    }

    /**
     * @param  array<string, string>  $codes
     */
    protected function resolvePaymentAccountCode(string $paymentMethod, array $codes): string
    {
        return match ($paymentMethod) {
            'card', 'mobile_money', 'bank' => $codes['bank'] ?? $codes['cash'],
            default => $codes['cash'],
        };
    }
}
