<?php

namespace App\Console\Commands;

use App\Models\Business;
use App\Models\SaleItem;
use App\Services\InventoryLedgerService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class SyncInventoryLedger extends Command
{
    protected $signature = 'inventory:sync-ledger
                            {business : Business ID}
                            {--dry-run : Show adjustment without posting}
                            {--force : Allow large adjustments}';

    protected $description = 'Align GL inventory (1104) with sanitized stock book value';

    public function handle(InventoryLedgerService $inventoryLedgerService): int {
        $businessId = (int) $this->argument('business');
        $business = Business::find($businessId);

        if (!$business) {
            $this->error("Business {$businessId} not found.");
            return 1;
        }

        SaleItem::query()
            ->whereNull('unit_cost')
            ->whereNotNull('product_id')
            ->update([
                'unit_cost' => DB::raw('(SELECT cost_price FROM products WHERE products.id = sale_items.product_id)'),
            ]);

        $report = $inventoryLedgerService->reconcile($businessId);
        $this->info("Business: {$business->name} (id={$businessId})");
        $this->line("  Stock book value:     {$report['stock_book_value']}");
        $this->line("  Raw (unfiltered):     {$report['raw_stock_book_value']}");
        $this->line("  GL inventory (1104):  {$report['gl_inventory_balance']}");
        $this->line("  Adjustment needed:    {$report['adjustment_needed']}");
        $this->line("  SKUs included:        {$report['included_sku_count']} (excluded: {$report['excluded_sku_count']})");

        if ($report['is_aligned']) {
            $this->info('Inventory ledger already aligned.');
            return 0;
        }

        if ($report['requires_force'] && !$this->option('force')) {
            $this->error('Adjustment exceeds safe limit — use --force after reviewing product data.');
            return 1;
        }

        if ($this->option('dry-run')) {
            $this->warn('Dry run — no journal entry posted.');
            return 0;
        }

        try {
            $result = $inventoryLedgerService->postOpeningBalance(
                $businessId,
                (int) $business->owner_id,
                (bool) $this->option('force'),
            );
        } catch (\RuntimeException $e) {
            $this->error($e->getMessage());
            return 1;
        }

        $this->info("Posted {$result['entry_number']}. GL inventory is now {$result['gl_inventory_balance']}.");
        return 0;
    }
}
