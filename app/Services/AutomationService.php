<?php

namespace App\Services;

use App\Models\ChartOfAccount;
use App\Models\Expense;
use App\Models\Sale;
use App\Models\SaleItem;
use Illuminate\Support\Facades\Log;

class AutomationService
{
    public function __construct(
        protected JournalEntryService $journalEntryService,
        protected LedgerService $ledgerService,
    ) {}

    public function handleSaleCreated(Sale $sale): void
    {
        try {
            $businessId = $sale->business_id;

            // Guard: skip if already accounted for
            if ($this->journalEntryService->getEntryByReference('sale', $sale->id, $businessId)) {
                return;
            }

            $codes = config('accounting.default_account_codes');
            $date = $sale->sale_date instanceof \Carbon\Carbon
                ? $sale->sale_date->toDateString()
                : now()->toDateString();

            $totalAmount = (float) $sale->total_amount;
            $subtotal = (float) $sale->subtotal;
            $taxTotal = (float) $sale->tax_total;

            $lines = [];

            $cashCode = $codes['cash'];
            $revenueCode = $codes['sales_revenue'];

            if ($taxTotal > 0) {
                $lines[] = ['account_code' => $cashCode, 'debit' => $totalAmount, 'credit' => 0, 'description' => "Sale {$sale->receipt_number} - cash received"];
                $lines[] = ['account_code' => $revenueCode, 'debit' => 0, 'credit' => $subtotal, 'description' => "Sale {$sale->receipt_number} - revenue"];
                $lines[] = ['account_code' => $codes['vat_payable'], 'debit' => 0, 'credit' => $taxTotal, 'description' => "Sale {$sale->receipt_number} - VAT"];
            } else {
                $lines[] = ['account_code' => $cashCode, 'debit' => $totalAmount, 'credit' => 0, 'description' => "Sale {$sale->receipt_number} - cash received"];
                $lines[] = ['account_code' => $revenueCode, 'debit' => 0, 'credit' => $totalAmount, 'description' => "Sale {$sale->receipt_number} - revenue"];
            }

            $cogsTotal = $this->calculateCOGS($sale);
            if ($cogsTotal > 0) {
                $lines[] = ['account_code' => $codes['cogs'], 'debit' => $cogsTotal, 'credit' => 0, 'description' => "Sale {$sale->receipt_number} - COGS"];
                $lines[] = ['account_code' => $codes['inventory'], 'debit' => 0, 'credit' => $cogsTotal, 'description' => "Sale {$sale->receipt_number} - inventory reduction"];
            }

            $this->journalEntryService->createAndPostEntry(
                $businessId, $date, "Journal entry for sale {$sale->receipt_number}",
                $lines, 'sale', $sale->id, $sale->user_id,
            );

            Log::info("Accounting automation: Sale created entry posted", [
                'sale_id' => $sale->id, 'receipt_number' => $sale->receipt_number,
                'total_amount' => $totalAmount, 'cogs' => $cogsTotal,
            ]);
        } catch (\Throwable $e) {
            Log::error("Accounting automation failed for sale {$sale->id}: {$e->getMessage()}", [
                'sale_id' => $sale->id, 'exception' => $e,
            ]);
        }
    }

    public function handleSaleRefunded(Sale $sale): void
    {
        try {
            $businessId = $sale->business_id;

            $originalEntry = $this->journalEntryService->getEntryByReference('sale', $sale->id, $businessId);

            if (!$originalEntry) {
                Log::warning("No original journal entry found for refunded sale", [
                    'sale_id' => $sale->id,
                    'receipt_number' => $sale->receipt_number,
                ]);
                return;
            }

            $reversing = $this->journalEntryService->createReversingEntry($originalEntry->id, $sale->user_id);
            $this->ledgerService->postEntryToLedger($reversing->id);

            Log::info("Accounting automation: Sale refund reversing entry posted", [
                'sale_id' => $sale->id,
                'receipt_number' => $sale->receipt_number,
                'original_entry_id' => $originalEntry->id,
                'reversing_entry_id' => $reversing->id,
            ]);
        } catch (\Throwable $e) {
            Log::error("Accounting automation failed for sale refund {$sale->id}: {$e->getMessage()}", [
                'sale_id' => $sale->id,
                'receipt_number' => $sale->receipt_number,
                'exception' => $e,
            ]);
        }
    }

    public function handleExpenseCreated(Expense $expense): void
    {
        try {
            $businessId = $expense->business_id;

            // Guard: skip if already accounted for
            if ($this->journalEntryService->getEntryByReference('expense', $expense->id, $businessId)) {
                return;
            }

            $codes = config('accounting.default_account_codes');
            $date = $expense->expense_date instanceof \Carbon\Carbon
                ? $expense->expense_date->toDateString()
                : now()->toDateString();

            $amount = (float) $expense->amount;
            $vatAmount = (float) ($expense->vat_amount ?? 0);

            // Use a generic operating expense account (6100 is Operating Expenses)
            $expenseAccountCode = $codes['operating_expense'] ?? '6101';
            $lines = [];

            $lines[] = [
                'account_code' => $expenseAccountCode,
                'debit' => $amount,
                'credit' => 0,
                'description' => $expense->description ?: "Expense #{$expense->id}",
            ];

            if ($vatAmount > 0) {
                $lines[] = ['account_code' => $codes['vat_payable'], 'debit' => $vatAmount, 'credit' => 0, 'description' => "Expense #{$expense->id} - input VAT"];
                $lines[] = ['account_code' => $codes['cash'], 'debit' => 0, 'credit' => $amount + $vatAmount, 'description' => "Expense #{$expense->id} - payment"];
            } else {
                $lines[] = ['account_code' => $codes['cash'], 'debit' => 0, 'credit' => $amount, 'description' => "Expense #{$expense->id} - payment"];
            }

            $this->journalEntryService->createAndPostEntry(
                $businessId, $date,
                "Journal entry for expense #{$expense->id}" . ($expense->description ? ": {$expense->description}" : ''),
                $lines, 'expense', $expense->id, $expense->recorded_by,
            );

            Log::info("Accounting automation: Expense created entry posted", [
                'expense_id' => $expense->id, 'amount' => $amount,
            ]);
        } catch (\Throwable $e) {
            Log::error("Accounting automation failed for expense {$expense->id}: {$e->getMessage()}", [
                'expense_id' => $expense->id, 'exception' => $e,
            ]);
        }
    }

    protected function calculateCOGS(Sale $sale): float
    {
        $sale->loadMissing('saleItems.product');
        $total = 0;

        foreach ($sale->saleItems as $item) {
            $product = $item->product;
            if ($product && (float) $product->cost_price > 0) {
                $total += (float) $product->cost_price * (int) $item->quantity;
            }
        }

        return $total;
    }
}
