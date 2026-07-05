<?php

namespace App\Listeners;

use App\Events\PaymentRecordedForAccounting;
use App\Models\Invoice;
use App\Models\Sale;
use App\Services\JournalEntryService;
use Illuminate\Support\Facades\Log;

class AccountForPaymentRecorded
{
    public function __construct(
        protected JournalEntryService $journalEntryService,
    ) {}

    public function handle(PaymentRecordedForAccounting $event): void
    {
        try {
            $payment = $event->payment->loadMissing('payable');
            $payable = $payment->payable;

            if ($payable instanceof Invoice) {
                $this->accountForInvoicePayment($payment, $payable);
            } elseif ($payable instanceof Sale) {
                $this->accountForSalePayment($payment, $payable);
            }
        } catch (\Throwable $e) {
            Log::error("Accounting automation failed for payment {$event->payment->id}: {$e->getMessage()}", [
                'payment_id' => $event->payment->id,
                'exception' => $e,
            ]);
        }
    }

    protected function accountForInvoicePayment(\App\Models\Payment $payment, Invoice $invoice): void
    {
        $businessId = $invoice->business_id;
        $amount = (float) $payment->amount;

        $originalEntry = $this->journalEntryService->getEntryByReference('invoice', $invoice->id, $businessId);
        if (!$originalEntry && $invoice->sale_id) {
            $originalEntry = $this->journalEntryService->getEntryByReference('sale', $invoice->sale_id, $businessId);
        }
        if (!$originalEntry) {
            Log::warning('No invoice journal entry found for payment', [
                'invoice_id' => $invoice->id,
                'payment_id' => $payment->id,
            ]);
            return;
        }

        $codes = config('accounting.default_account_codes');
        $paymentAccountCode = $this->resolvePaymentAccountCode($payment->payment_method, $codes);

        $lines = [
            [
                'account_code' => $paymentAccountCode,
                'debit' => $amount,
                'credit' => 0,
                'description' => "Payment {$payment->receipt_number} for Invoice {$invoice->invoice_number}",
            ],
            [
                'account_code' => $codes['accounts_receivable'],
                'debit' => 0,
                'credit' => $amount,
                'description' => "Payment {$payment->receipt_number} for Invoice {$invoice->invoice_number}",
            ],
        ];

        $this->journalEntryService->createAndPostEntry(
            $businessId,
            $payment->paid_at->toDateString(),
            "Payment {$payment->receipt_number} for Invoice {$invoice->invoice_number}",
            $lines,
            'invoice_payment',
            $payment->id,
            $payment->recorded_by,
        );
    }

    protected function accountForSalePayment(\App\Models\Payment $payment, Sale $sale): void
    {
        $businessId = $sale->business_id;
        $amount = (float) $payment->amount;

        $originalEntry = $this->journalEntryService->getEntryByReference('sale', $sale->id, $businessId);
        if (!$originalEntry) {
            Log::warning('No sale journal entry found for payment', [
                'sale_id' => $sale->id,
                'payment_id' => $payment->id,
            ]);
            return;
        }

        $codes = config('accounting.default_account_codes');
        $paymentAccountCode = $this->resolvePaymentAccountCode($payment->payment_method, $codes);

        $lines = [
            [
                'account_code' => $paymentAccountCode,
                'debit' => $amount,
                'credit' => 0,
                'description' => "Payment {$payment->receipt_number} for Sale {$sale->receipt_number}",
            ],
            [
                'account_code' => $codes['accounts_receivable'],
                'debit' => 0,
                'credit' => $amount,
                'description' => "Payment {$payment->receipt_number} for Sale {$sale->receipt_number}",
            ],
        ];

        $this->journalEntryService->createAndPostEntry(
            $businessId,
            $payment->paid_at->toDateString(),
            "Payment {$payment->receipt_number} for Sale {$sale->receipt_number}",
            $lines,
            'sale_payment',
            $payment->id,
            $payment->recorded_by,
        );
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
