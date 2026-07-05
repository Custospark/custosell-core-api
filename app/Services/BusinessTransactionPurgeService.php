<?php

namespace App\Services;

use App\Models\AccountingPeriod;
use App\Models\Business;
use App\Models\ChartOfAccount;
use App\Models\Customer;
use App\Models\DepreciationEntry;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\FixedAsset;
use App\Models\GeneralLedger;
use App\Models\Invoice;
use App\Models\JournalEntry;
use App\Models\JournalEntryLine;
use App\Models\Notification;
use App\Models\Payment;
use App\Models\Product;
use App\Models\Sale;
use App\Models\Shift;
use App\Models\StockMovement;
use Illuminate\Support\Facades\DB;

class BusinessTransactionPurgeService
{
    /**
     * Remove all transactional data for a business while preserving products,
     * categories, chart of accounts, users, business settings, and accounting periods.
     *
     * @return array<string, int>
     */
    public function purge(Business $business, bool $includeCustomers = true): array
    {
        $businessId = $business->id;
        $counts = [];

        DB::transaction(function () use ($businessId, $includeCustomers, &$counts): void {
            $journalEntryIds = JournalEntry::withTrashed()
                ->where('business_id', $businessId)
                ->pluck('id');

            $fixedAssetIds = FixedAsset::withTrashed()
                ->where('business_id', $businessId)
                ->pluck('id');

            $counts['depreciation_entries'] = DepreciationEntry::query()
                ->where(function ($query) use ($journalEntryIds, $fixedAssetIds): void {
                    $query->whereIn('journal_entry_id', $journalEntryIds)
                        ->orWhereIn('asset_id', $fixedAssetIds);
                })
                ->delete();

            $counts['journal_entry_lines'] = JournalEntryLine::query()
                ->whereIn('entry_id', $journalEntryIds)
                ->delete();

            $counts['journal_entries'] = JournalEntry::withTrashed()
                ->where('business_id', $businessId)
                ->forceDelete();

            $counts['general_ledger'] = GeneralLedger::where('business_id', $businessId)->delete();

            $counts['payments'] = Payment::where('business_id', $businessId)->delete();

            $counts['invoices'] = Invoice::withTrashed()
                ->where('business_id', $businessId)
                ->forceDelete();

            $counts['stock_movements'] = StockMovement::where('business_id', $businessId)->delete();

            $counts['sales'] = Sale::withTrashed()
                ->where('business_id', $businessId)
                ->forceDelete();

            $counts['expenses'] = Expense::withTrashed()
                ->where('business_id', $businessId)
                ->forceDelete();

            $counts['shifts'] = Shift::where('business_id', $businessId)->delete();

            $counts['fixed_assets'] = FixedAsset::withTrashed()
                ->where('business_id', $businessId)
                ->forceDelete();

            if ($includeCustomers) {
                $counts['customers'] = Customer::where('business_id', $businessId)->delete();
            }

            $counts['notifications'] = Notification::where('business_id', $businessId)->delete();
        });

        $counts['products_kept'] = Product::where('business_id', $businessId)->count();
        $counts['chart_of_accounts_kept'] = ChartOfAccount::where('business_id', $businessId)->count();
        $counts['expense_categories_kept'] = ExpenseCategory::where('business_id', $businessId)->count();
        $counts['accounting_periods_kept'] = AccountingPeriod::where('business_id', $businessId)->count();

        return $counts;
    }
}
