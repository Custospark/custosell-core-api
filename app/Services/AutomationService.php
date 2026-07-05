<?php

namespace App\Services;

use App\Models\Expense;
use App\Models\Sale;
use Illuminate\Support\Facades\Log;

class AutomationService
{
    public function __construct(
        protected JournalEntryService $journalEntryService,
        protected LedgerService $ledgerService,
        protected InventoryCogsService $inventoryCogsService,
    ) {}

    public function handleSaleCreated(Sale $sale): void
    {
        try {
            $businessId = $sale->business_id;

            if ($this->journalEntryService->getEntryByReference('sale', $sale->id, $businessId)) {
                return;
            }

            $totalAmount = (float) $sale->total_amount;
            $amountPaid = (float) ($sale->amount_paid ?? 0);
            $isCreditSale = $amountPaid < $totalAmount - 0.001;

            if ($isCreditSale) {
                $this->postCreditSaleEntry($sale);
                return;
            }

            $this->postCashSaleEntry($sale);
        } catch (\Throwable $e) {
            Log::error("Accounting automation failed for sale {$sale->id}: {$e->getMessage()}", [
                'sale_id' => $sale->id, 'exception' => $e,
            ]);
        }
    }

    protected function postCashSaleEntry(Sale $sale): void
    {
        $businessId = $sale->business_id;
        $codes = config('accounting.default_account_codes');
        $date = $sale->sale_date instanceof \Carbon\Carbon
            ? $sale->sale_date->toDateString()
            : now()->toDateString();

        $totalAmount = (float) $sale->total_amount;
        $subtotal = (float) $sale->subtotal;
        $taxTotal = (float) $sale->tax_total;
        $paymentAccount = $this->resolvePaymentAccountCode((string) $sale->payment_method, $codes);

        $lines = [];

        if ($taxTotal > 0) {
            $lines[] = ['account_code' => $paymentAccount, 'debit' => $totalAmount, 'credit' => 0, 'description' => "Sale {$sale->receipt_number} - payment received"];
            $lines[] = ['account_code' => $codes['sales_revenue'], 'debit' => 0, 'credit' => $subtotal, 'description' => "Sale {$sale->receipt_number} - revenue"];
            $lines[] = ['account_code' => $codes['vat_payable'], 'debit' => 0, 'credit' => $taxTotal, 'description' => "Sale {$sale->receipt_number} - VAT"];
        } else {
            $lines[] = ['account_code' => $paymentAccount, 'debit' => $totalAmount, 'credit' => 0, 'description' => "Sale {$sale->receipt_number} - payment received"];
            $lines[] = ['account_code' => $codes['sales_revenue'], 'debit' => 0, 'credit' => $totalAmount, 'description' => "Sale {$sale->receipt_number} - revenue"];
        }

        $cogsTotal = $this->inventoryCogsService->calculateSaleCogs($sale);
        $this->appendCogsLines($lines, $businessId, $cogsTotal, "Sale {$sale->receipt_number}");

        $this->journalEntryService->createAndPostEntry(
            $businessId, $date, "Journal entry for sale {$sale->receipt_number}",
            $lines, 'sale', $sale->id, $sale->user_id,
        );

        Log::info("Accounting automation: Cash sale entry posted", [
            'sale_id' => $sale->id, 'receipt_number' => $sale->receipt_number, 'total_amount' => $totalAmount,
        ]);
    }

    protected function postCreditSaleEntry(Sale $sale): void
    {
        $businessId = $sale->business_id;
        $codes = config('accounting.default_account_codes');
        $date = $sale->sale_date instanceof \Carbon\Carbon
            ? $sale->sale_date->toDateString()
            : now()->toDateString();

        $totalAmount = (float) $sale->total_amount;
        $subtotal = (float) $sale->subtotal;
        $taxTotal = (float) $sale->tax_total;
        $arCode = $codes['accounts_receivable'];
        $revenueCode = $codes['sales_revenue'];

        $lines = [];

        if ($taxTotal > 0) {
            $lines[] = ['account_code' => $arCode, 'debit' => $totalAmount, 'credit' => 0, 'description' => "Credit sale {$sale->receipt_number} - receivable"];
            $lines[] = ['account_code' => $revenueCode, 'debit' => 0, 'credit' => $subtotal, 'description' => "Credit sale {$sale->receipt_number} - revenue"];
            $lines[] = ['account_code' => $codes['vat_payable'], 'debit' => 0, 'credit' => $taxTotal, 'description' => "Credit sale {$sale->receipt_number} - VAT"];
        } else {
            $lines[] = ['account_code' => $arCode, 'debit' => $totalAmount, 'credit' => 0, 'description' => "Credit sale {$sale->receipt_number} - receivable"];
            $lines[] = ['account_code' => $revenueCode, 'debit' => 0, 'credit' => $totalAmount, 'description' => "Credit sale {$sale->receipt_number} - revenue"];
        }

        $cogsTotal = $this->inventoryCogsService->calculateSaleCogs($sale);
        $this->appendCogsLines($lines, $businessId, $cogsTotal, "Credit sale {$sale->receipt_number}");

        $this->journalEntryService->createAndPostEntry(
            $businessId, $date, "Credit sale {$sale->receipt_number}",
            $lines, 'sale', $sale->id, $sale->user_id,
        );

        Log::info("Accounting automation: Credit sale entry posted", [
            'sale_id' => $sale->id, 'receipt_number' => $sale->receipt_number, 'total_amount' => $totalAmount,
        ]);
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

    public function handleSaleRefunded(Sale $sale, array $refundBatch = []): void
    {
        try {
            if ($refundBatch === []) {
                Log::warning('Sale refund accounting skipped: empty refund batch', ['sale_id' => $sale->id]);
                return;
            }

            $businessId = $sale->business_id;
            $codes = config('accounting.default_account_codes');
            $date = $sale->sale_date instanceof \Carbon\Carbon
                ? $sale->sale_date->toDateString()
                : now()->toDateString();

            $refundTotal = round(collect($refundBatch)->sum(fn (array $row) => (float) ($row['proportionalAmount'] ?? 0)), 2);
            $cogsRestore = $this->inventoryCogsService->calculateRefundCogs($refundBatch);

            if ($refundTotal <= 0 && $cogsRestore <= 0) {
                return;
            }

            $lines = [];
            $paymentAccount = $this->resolvePaymentAccountCode((string) $sale->payment_method, $codes);
            $returnsCode = $codes['sales_returns'] ?? '4400';

            if ($refundTotal > 0) {
                $lines[] = [
                    'account_code' => $returnsCode,
                    'debit' => $refundTotal,
                    'credit' => 0,
                    'description' => "Refund {$sale->receipt_number} - sales return",
                ];
                $lines[] = [
                    'account_code' => $paymentAccount,
                    'debit' => 0,
                    'credit' => $refundTotal,
                    'description' => "Refund {$sale->receipt_number} - settlement",
                ];
            }

            if ($cogsRestore > 0) {
                $lines[] = [
                    'account_code' => $codes['inventory'],
                    'debit' => $cogsRestore,
                    'credit' => 0,
                    'description' => "Refund {$sale->receipt_number} - inventory restored",
                ];
                $lines[] = [
                    'account_code' => $codes['cogs'],
                    'debit' => 0,
                    'credit' => $cogsRestore,
                    'description' => "Refund {$sale->receipt_number} - COGS reversal",
                ];
            }

            $this->journalEntryService->createAndPostEntry(
                $businessId,
                $date,
                "Refund for sale {$sale->receipt_number}",
                $lines,
                'sale_refund',
                $sale->id,
                $sale->user_id,
            );

            Log::info('Accounting automation: Sale refund entry posted', [
                'sale_id' => $sale->id,
                'receipt_number' => $sale->receipt_number,
                'refund_total' => $refundTotal,
                'cogs_restore' => $cogsRestore,
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

    /**
     * @param  array<int, array<string, mixed>>  $lines
     */
    protected function appendCogsLines(array &$lines, int $businessId, float $requestedCogs, string $label): void
    {
        if ($requestedCogs <= 0) {
            return;
        }

        $codes = config('accounting.default_account_codes');
        $periodId = app(AccountingPeriodService::class)->getCurrentPeriod($businessId)->id;
        $capped = $this->inventoryCogsService->capCogsToAvailableInventory($businessId, $requestedCogs, $periodId);
        $cogsTotal = $capped['cogs'];

        if ($cogsTotal <= 0) {
            return;
        }

        $lines[] = ['account_code' => $codes['cogs'], 'debit' => $cogsTotal, 'credit' => 0, 'description' => "{$label} - COGS"];
        $lines[] = ['account_code' => $codes['inventory'], 'debit' => 0, 'credit' => $cogsTotal, 'description' => "{$label} - inventory reduction"];
    }

    protected function calculateCOGS(Sale $sale): float
    {
        return $this->inventoryCogsService->calculateSaleCogs($sale);
    }
}
