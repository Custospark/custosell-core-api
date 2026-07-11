<?php

namespace App\Services;

use App\Models\Business;
use App\Models\Invoice;
use App\Models\Payment;
use Illuminate\Support\Facades\Log;

/**
 * Posts buyer-side AP journals for shared B2B invoices (buyer_business_id set).
 *
 * Seller AR remains in AccountForInvoiceSent / AccountForPaymentRecorded.
 * Buyer books: Dr Inventory|Expense (+ input VAT) / Cr AP on send;
 *              Dr AP / Cr Cash|Bank when the seller records payment.
 */
class SupplierInvoiceAccountingService
{
    public const REF_INVOICE = 'supplier_invoice';

    public const REF_PAYMENT = 'supplier_invoice_payment';

    public function __construct(
        protected JournalEntryService $journalEntryService,
    ) {}

    public function postBuyerOnInvoiceSent(Invoice $invoice): void
    {
        $buyerBusinessId = (int) ($invoice->buyer_business_id ?? 0);
        if ($buyerBusinessId <= 0 || $buyerBusinessId === (int) $invoice->business_id) {
            return;
        }

        if ($this->journalEntryService->getEntryByReference(self::REF_INVOICE, $invoice->id, $buyerBusinessId)) {
            return;
        }

        $invoice->loadMissing(['items.product', 'business']);
        $codes = config('accounting.default_account_codes');
        $date = $invoice->issue_date instanceof \Carbon\Carbon
            ? $invoice->issue_date->toDateString()
            : now()->toDateString();

        $totalAmount = round((float) $invoice->total_amount, 2);
        $subtotal = round((float) $invoice->subtotal, 2);
        $taxTotal = round((float) $invoice->tax_total, 2);
        $supplierName = $invoice->business?->name ?? 'Supplier';

        [$inventorySum, $expenseSum] = $this->splitBuyerCostBases($invoice, $subtotal);

        $lines = [];

        if ($inventorySum > 0.009) {
            $lines[] = [
                'account_code' => $codes['inventory'],
                'debit' => $inventorySum,
                'credit' => 0,
                'description' => "Supplier invoice {$invoice->invoice_number} — inventory",
            ];
        }

        if ($expenseSum > 0.009) {
            $lines[] = [
                'account_code' => $codes['operating_expense'] ?? '6101',
                'debit' => $expenseSum,
                'credit' => 0,
                'description' => "Supplier invoice {$invoice->invoice_number} — services/expense",
            ];
        }

        if ($taxTotal > 0.009) {
            // Input VAT reduces VAT payable until a dedicated VAT receivable account exists.
            $lines[] = [
                'account_code' => $codes['vat_payable'],
                'debit' => $taxTotal,
                'credit' => 0,
                'description' => "Supplier invoice {$invoice->invoice_number} — input VAT",
            ];
        }

        $lines[] = [
            'account_code' => $codes['accounts_payable'],
            'debit' => 0,
            'credit' => $totalAmount,
            'description' => "Supplier invoice {$invoice->invoice_number} from {$supplierName}",
        ];

        $debitTotal = collect($lines)->sum(fn ($l) => (float) $l['debit']);
        if (abs($debitTotal - $totalAmount) > 0.02) {
            Log::warning('Supplier invoice buyer JE skipped — unbalanced draft', [
                'invoice_id' => $invoice->id,
                'debit_total' => $debitTotal,
                'total_amount' => $totalAmount,
            ]);

            return;
        }

        $this->journalEntryService->createAndPostEntry(
            $buyerBusinessId,
            $date,
            "Supplier invoice {$invoice->invoice_number} from {$supplierName}",
            $lines,
            self::REF_INVOICE,
            $invoice->id,
            $this->actorForBuyerBusiness($buyerBusinessId, $invoice->created_by),
        );

        Log::info('Accounting automation: buyer supplier invoice AP posted', [
            'invoice_id' => $invoice->id,
            'buyer_business_id' => $buyerBusinessId,
            'total_amount' => $totalAmount,
        ]);
    }

    public function postBuyerOnPayment(Payment $payment, Invoice $invoice): void
    {
        $buyerBusinessId = (int) ($invoice->buyer_business_id ?? 0);
        if ($buyerBusinessId <= 0 || $buyerBusinessId === (int) $invoice->business_id) {
            return;
        }

        if ($this->journalEntryService->getEntryByReference(self::REF_PAYMENT, $payment->id, $buyerBusinessId)) {
            return;
        }

        $apEntry = $this->journalEntryService->getEntryByReference(self::REF_INVOICE, $invoice->id, $buyerBusinessId);
        if (!$apEntry) {
            Log::warning('No buyer supplier_invoice JE found for payment settlement', [
                'invoice_id' => $invoice->id,
                'payment_id' => $payment->id,
                'buyer_business_id' => $buyerBusinessId,
            ]);

            return;
        }

        $codes = config('accounting.default_account_codes');
        $amount = round((float) $payment->amount, 2);
        $paymentAccountCode = $this->resolvePaymentAccountCode($payment->payment_method, $codes);

        $lines = [
            [
                'account_code' => $codes['accounts_payable'],
                'debit' => $amount,
                'credit' => 0,
                'description' => "Payment {$payment->receipt_number} on supplier invoice {$invoice->invoice_number}",
            ],
            [
                'account_code' => $paymentAccountCode,
                'debit' => 0,
                'credit' => $amount,
                'description' => "Payment {$payment->receipt_number} on supplier invoice {$invoice->invoice_number}",
            ],
        ];

        $paidAt = $payment->paid_at
            ? $payment->paid_at->toDateString()
            : now()->toDateString();

        $this->journalEntryService->createAndPostEntry(
            $buyerBusinessId,
            $paidAt,
            "Payment {$payment->receipt_number} on supplier invoice {$invoice->invoice_number}",
            $lines,
            self::REF_PAYMENT,
            $payment->id,
            $this->actorForBuyerBusiness($buyerBusinessId, $payment->recorded_by),
        );

        Log::info('Accounting automation: buyer AP settlement posted', [
            'invoice_id' => $invoice->id,
            'payment_id' => $payment->id,
            'buyer_business_id' => $buyerBusinessId,
            'amount' => $amount,
        ]);
    }

    /**
     * Product lines → Inventory; service / description-only → operating expense.
     *
     * @return array{0: float, 1: float}
     */
    protected function splitBuyerCostBases(Invoice $invoice, float $subtotal): array
    {
        $inventorySum = 0.0;
        $expenseSum = 0.0;

        foreach ($invoice->items as $item) {
            $amount = (float) $item->subtotal;
            if (!$item->product_id) {
                $expenseSum += $amount;
                continue;
            }
            if ($item->product && $item->product->isService()) {
                $expenseSum += $amount;
            } else {
                $inventorySum += $amount;
            }
        }

        $lineSum = $inventorySum + $expenseSum;
        if ($lineSum <= 0) {
            return [round($subtotal, 2), 0.0];
        }

        if (abs($lineSum - $subtotal) > 0.009) {
            $scale = $subtotal / $lineSum;
            $inventorySum = round($inventorySum * $scale, 2);
            $expenseSum = round($subtotal - $inventorySum, 2);
        } else {
            $inventorySum = round($inventorySum, 2);
            $expenseSum = round($subtotal - $inventorySum, 2);
        }

        return [$inventorySum, $expenseSum];
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

    protected function actorForBuyerBusiness(int $buyerBusinessId, ?int $fallbackUserId): ?int
    {
        $ownerId = Business::query()->where('id', $buyerBusinessId)->value('owner_id');

        return $ownerId ? (int) $ownerId : $fallbackUserId;
    }
}
