<?php

namespace App\Services;

use App\Models\Business;
use App\Models\Product;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class InventoryLedgerService
{
    public function __construct(
        protected InventoryCogsService $inventoryCogsService,
        protected JournalEntryService $journalEntryService,
        protected AccountingPeriodService $periodService,
    ) {}

    /**
     * @return array{
     *   stock_book_value: float,
     *   raw_stock_book_value: float,
     *   gl_inventory_balance: float,
     *   adjustment_needed: float,
     *   is_aligned: bool,
     *   included_sku_count: int,
     *   excluded_sku_count: int,
     *   warnings: array<int, string>,
     *   excluded_samples: array<int, array<string, mixed>>,
     *   can_post_opening: bool,
     *   requires_force: bool
     * }
     */
    public function reconcile(int $businessId): array
    {
        $analysis = $this->analyzeStock($businessId);
        $glBalance = $this->inventoryCogsService->getInventoryLedgerBalance($businessId);
        $target = $analysis['included_value'];
        $adjustment = round($target - $glBalance, 2);
        $maxAuto = (float) config('accounting.inventory.auto_sync_max_adjustment', 100_000);

        $warnings = $analysis['warnings'];
        if ($analysis['excluded_count'] > 0) {
            $warnings[] = "{$analysis['excluded_count']} product(s) excluded due to unrealistic stock or cost — review Inventory.";
        }

        return [
            'stock_book_value' => $target,
            'raw_stock_book_value' => $analysis['raw_value'],
            'gl_inventory_balance' => $glBalance,
            'adjustment_needed' => $adjustment,
            'is_aligned' => abs($adjustment) < 0.01,
            'included_sku_count' => $analysis['included_count'],
            'excluded_sku_count' => $analysis['excluded_count'],
            'warnings' => $warnings,
            'excluded_samples' => $analysis['excluded_samples'],
            'can_post_opening' => abs($adjustment) >= 0.01,
            'requires_force' => abs($adjustment) > $maxAuto,
            'tracked_products_only' => $analysis['tracked_products_only'] ?? true,
        ];
    }

    /**
     * Post a balanced journal entry to align GL inventory with sanitized stock book value.
     *
     * @return array{entry_number: string, gl_inventory_balance: float, adjustment_posted: float}
     */
    public function postOpeningBalance(int $businessId, int $userId, bool $force = false): array
    {
        $report = $this->reconcile($businessId);
        $adjustment = $report['adjustment_needed'];

        if (abs($adjustment) < 0.01) {
            throw new RuntimeException('Inventory ledger already matches stock book value.');
        }

        if ($report['requires_force'] && !$force) {
            throw new RuntimeException(
                'Adjustment exceeds the safe auto-limit. Review excluded products and confirm with force=true.'
            );
        }

        if ($report['included_sku_count'] === 0 && $adjustment > 0) {
            throw new RuntimeException(
                'No eligible products with stock and cost — fix product data before establishing opening inventory.'
            );
        }

        $business = Business::findOrFail($businessId);
        $period = $this->periodService->getCurrentPeriod($businessId);
        $codes = config('accounting.default_account_codes');
        $date = now()->toDateString();

        if ($adjustment > 0) {
            $lines = [
                ['account_code' => $codes['inventory'], 'debit' => $adjustment, 'credit' => 0, 'description' => 'Opening inventory from stock on hand'],
                ['account_code' => $codes['retained_earnings'], 'debit' => 0, 'credit' => $adjustment, 'description' => 'Opening inventory offset'],
            ];
            $description = 'Establish opening inventory from product stock';
        } else {
            $amount = abs($adjustment);
            $lines = [
                ['account_code' => $codes['cogs'], 'debit' => $amount, 'credit' => 0, 'description' => 'Inventory adjustment to match stock'],
                ['account_code' => $codes['inventory'], 'debit' => 0, 'credit' => $amount, 'description' => 'Inventory adjustment offset'],
            ];
            $description = 'Reduce inventory ledger to match product stock';
        }

        $entry = $this->journalEntryService->createAndPostEntry(
            $businessId,
            $date,
            $description,
            $lines,
            'inventory_opening',
            $period->id,
            $userId ?: $business->owner_id,
        );

        $after = $this->inventoryCogsService->getInventoryLedgerBalance($businessId);

        Log::info('Opening inventory posted', [
            'business_id' => $businessId,
            'adjustment' => $adjustment,
            'entry_number' => $entry->entry_number,
            'gl_after' => $after,
        ]);

        return [
            'entry_number' => $entry->entry_number,
            'gl_inventory_balance' => $after,
            'adjustment_posted' => $adjustment,
        ];
    }

    /**
     * @return array{
     *   included_value: float,
     *   raw_value: float,
     *   included_count: int,
     *   excluded_count: int,
     *   warnings: array<int, string>,
     *   excluded_samples: array<int, array<string, mixed>>
     * }
     */
    public function analyzeStock(int $businessId): array
    {
        $limits = config('accounting.inventory');
        $maxStock = (int) ($limits['max_stock_per_sku'] ?? 100_000);
        $maxCost = (float) ($limits['max_unit_cost'] ?? 1_000_000);
        $maxLine = (float) ($limits['max_line_value'] ?? 10_000_000);
        $trackedOnly = (bool) ($limits['opening_tracked_products_only'] ?? true);

        $trackedProductIds = $trackedOnly
            ? \App\Models\StockMovement::query()
                ->where('business_id', $businessId)
                ->distinct()
                ->pluck('product_id')
                ->filter()
                ->flip()
            : collect();

        $products = Product::query()
            ->where('business_id', $businessId)
            ->where('is_active', true)
            ->when($trackedOnly, fn ($q) => $q->whereIn('id', $trackedProductIds->keys()->all() ?: [0]))
            ->get(['id', 'name', 'sku', 'stock_quantity', 'cost_price']);

        $includedValue = 0.0;
        $rawValue = 0.0;
        $includedCount = 0;
        $excluded = [];

        foreach ($products as $product) {
            $stock = (int) $product->stock_quantity;
            $cost = (float) $product->cost_price;
            $lineValue = $stock * $cost;
            $rawValue += $lineValue;

            if ($stock <= 0 || $cost <= 0) {
                continue;
            }

            $reason = $this->exclusionReason($stock, $cost, $lineValue, $maxStock, $maxCost, $maxLine);
            if ($reason !== null) {
                if (count($excluded) < 10) {
                    $excluded[] = [
                        'id' => $product->id,
                        'name' => $product->name,
                        'sku' => $product->sku,
                        'stock_quantity' => $stock,
                        'cost_price' => $cost,
                        'line_value' => round($lineValue, 2),
                        'reason' => $reason,
                    ];
                }
                continue;
            }

            $includedValue += $lineValue;
            $includedCount++;
        }

        $excludedCount = $products->filter(function (Product $product) use ($maxStock, $maxCost, $maxLine) {
            $stock = (int) $product->stock_quantity;
            $cost = (float) $product->cost_price;
            if ($stock <= 0 || $cost <= 0) {
                return false;
            }

            return $this->exclusionReason($stock, $cost, $stock * $cost, $maxStock, $maxCost, $maxLine) !== null;
        })->count();

        $warnings = [];
        if ($trackedOnly && $trackedProductIds->isEmpty()) {
            $warnings[] = 'No stock movements yet — record sales or stock adjustments before establishing opening inventory.';
        } elseif ($trackedOnly) {
            $warnings[] = 'Only products with stock movement history are included (operational catalog).';
        }

        return [
            'included_value' => round($includedValue, 2),
            'raw_value' => round($rawValue, 2),
            'included_count' => $includedCount,
            'excluded_count' => $excludedCount,
            'warnings' => $warnings,
            'excluded_samples' => $excluded,
            'tracked_products_only' => $trackedOnly,
        ];
    }

    public function calculateSanitizedStockBookValue(int $businessId): float
    {
        return $this->analyzeStock($businessId)['included_value'];
    }

    protected function exclusionReason(
        int $stock,
        float $cost,
        float $lineValue,
        int $maxStock,
        float $maxCost,
        float $maxLine,
    ): ?string {
        if ($stock > $maxStock) {
            return "Stock quantity exceeds {$maxStock}";
        }
        if ($cost > $maxCost) {
            return "Unit cost exceeds maximum";
        }
        if ($lineValue > $maxLine) {
            return 'Line value exceeds maximum';
        }

        return null;
    }
}
