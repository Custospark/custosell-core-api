<?php

namespace App\Services;

use App\Models\AccountingPeriod;
use App\Models\ChartOfAccount;
use App\Models\DepreciationEntry;
use App\Models\FixedAsset;
use App\Repositories\Contracts\FixedAssetRepositoryInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class DepreciationService
{
    public function __construct(
        protected FixedAssetRepositoryInterface $fixedAssetRepository,
        protected JournalEntryService $journalEntryService,
        protected LedgerService $ledgerService,
    ) {}

    public function straightLineMonthlyDepreciation(FixedAsset $asset): float
    {
        $cost = (float) $asset->cost;
        $salvage = (float) $asset->salvage_value;
        $usefulLife = (int) $asset->useful_life_months;

        if ($usefulLife <= 0) {
            return 0;
        }

        $depreciableAmount = $cost - $salvage;

        if ($depreciableAmount <= 0) {
            return 0;
        }

        return round($depreciableAmount / $usefulLife, 2);
    }

    public function calculateDepreciationForPeriod(FixedAsset $asset, AccountingPeriod $period): ?DepreciationEntry
    {
        return DB::transaction(function () use ($asset, $period) {
            $monthlyDepreciation = $this->straightLineMonthlyDepreciation($asset);
            if ($monthlyDepreciation <= 0) {
                return null;
            }

            $lastEntry = DepreciationEntry::where('asset_id', $asset->id)
                ->orderBy('id', 'desc')
                ->first();

            $currentAccumulated = $lastEntry ? (float) $lastEntry->accumulated_depreciation : 0;
            $currentBookValue = $lastEntry ? (float) $lastEntry->book_value_after : (float) $asset->book_value;

            $salvageValue = (float) $asset->salvage_value;

            if ($currentBookValue - $monthlyDepreciation < $salvageValue) {
                $monthlyDepreciation = $currentBookValue - $salvageValue;
            }

            if ($monthlyDepreciation <= 0) {
                return null;
            }

            $newAccumulated = $currentAccumulated + $monthlyDepreciation;
            $newBookValue = $currentBookValue - $monthlyDepreciation;

            $depreciationExpenseCode = config('accounting.default_account_codes.depreciation_expense', '6300');
            $accumulatedDepreciationCode = config('accounting.default_account_codes.accumulated_depreciation', '1205');

            $expenseAccount = ChartOfAccount::where('business_id', $asset->business_id)
                ->where('code', $depreciationExpenseCode)
                ->first();

            $accumulatedAccount = ChartOfAccount::where('business_id', $asset->business_id)
                ->where('code', $accumulatedDepreciationCode)
                ->first();

            if (!$expenseAccount || !$accumulatedAccount) {
                throw new \RuntimeException("Depreciation accounts not configured for this business.");
            }

            $entry = $this->journalEntryService->createAndPostEntry(
                $asset->business_id,
                $period->end_date->toDateString(),
                "Depreciation for {$asset->name} - {$period->name}",
                [
                    [
                        'account_code' => $depreciationExpenseCode,
                        'debit' => $monthlyDepreciation,
                        'credit' => 0,
                        'description' => "Depreciation expense - {$asset->name}",
                    ],
                    [
                        'account_code' => $accumulatedDepreciationCode,
                        'debit' => 0,
                        'credit' => $monthlyDepreciation,
                        'description' => "Accumulated depreciation - {$asset->name}",
                    ],
                ],
                'fixed_asset',
                $asset->id,
            );

            $this->ledgerService->postEntryToLedger($entry->id);

            $depreciationEntry = DepreciationEntry::create([
                'asset_id' => $asset->id,
                'period_id' => $period->id,
                'journal_entry_id' => $entry->id,
                'amount' => $monthlyDepreciation,
                'accumulated_depreciation' => $newAccumulated,
                'book_value_after' => $newBookValue,
            ]);

            $asset->book_value = $newBookValue;
            $asset->save();

            Log::info("Depreciation recorded for asset {$asset->name}", [
                'asset_id' => $asset->id,
                'period_id' => $period->id,
                'amount' => $monthlyDepreciation,
                'accumulated' => $newAccumulated,
                'book_value_after' => $newBookValue,
            ]);

            return $depreciationEntry;
        });
    }

    public function runDepreciation(int $businessId, int $periodId, int $userId): array
    {
        $period = AccountingPeriod::find($periodId);
        if (!$period) {
            throw new \RuntimeException("Accounting period not found.");
        }

        $assets = $this->fixedAssetRepository->getDueForDepreciation($businessId, $periodId);
        $results = [];

        foreach ($assets as $asset) {
            try {
                $entry = $this->calculateDepreciationForPeriod($asset, $period);
                $results[] = [
                    'asset_id' => $asset->id,
                    'asset_name' => $asset->name,
                    'amount' => $entry ? (float) $entry->amount : 0,
                    'status' => $entry ? 'depreciated' : 'skipped',
                ];
            } catch (\Throwable $e) {
                Log::error("Depreciation failed for asset {$asset->id}", [
                    'error' => $e->getMessage(),
                ]);
                $results[] = [
                    'asset_id' => $asset->id,
                    'asset_name' => $asset->name,
                    'status' => 'failed',
                    'error' => $e->getMessage(),
                ];
            }
        }

        return $results;
    }

    public function getSchedule(int $assetId): \Illuminate\Support\Collection
    {
        return DepreciationEntry::where('asset_id', $assetId)
            ->with(['accountingPeriod', 'journalEntry'])
            ->orderBy('id')
            ->get();
    }
}
