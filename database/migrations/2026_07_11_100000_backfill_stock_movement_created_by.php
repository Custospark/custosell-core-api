<?php

use App\Models\Business;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\StockMovement;
use Illuminate\Database\Migrations\Migration;

/**
 * Historical stock_movements often lack created_by (sales/refunds/imports).
 * Attribute them to the cashier (via sale) or the business owner when possible.
 * Uses Eloquent so the backfill works on MySQL (prod) and SQLite (tests).
 */
return new class extends Migration
{
    public function up(): void
    {
        StockMovement::query()
            ->whereNull('created_by')
            ->whereNotNull('sale_item_id')
            ->orderBy('id')
            ->chunkById(200, function ($movements) {
                $saleItemIds = $movements->pluck('sale_item_id')->unique()->filter()->all();
                $saleItems = SaleItem::query()
                    ->whereIn('id', $saleItemIds)
                    ->with('sale:id,user_id')
                    ->get()
                    ->keyBy('id');

                foreach ($movements as $movement) {
                    $userId = $saleItems->get($movement->sale_item_id)?->sale?->user_id;
                    if ($userId) {
                        $movement->forceFill(['created_by' => $userId])->saveQuietly();
                    }
                }
            });

        StockMovement::query()
            ->whereNull('created_by')
            ->where('type', 'sale')
            ->whereNotNull('notes')
            ->orderBy('id')
            ->chunkById(200, function ($movements) {
                foreach ($movements as $movement) {
                    if (! preg_match('/^Sale\s+(.+)$/', (string) $movement->notes, $m)) {
                        continue;
                    }
                    $userId = Sale::query()
                        ->where('business_id', $movement->business_id)
                        ->where('receipt_number', $m[1])
                        ->value('user_id');
                    if ($userId) {
                        $movement->forceFill(['created_by' => $userId])->saveQuietly();
                    }
                }
            });

        StockMovement::query()
            ->whereNull('created_by')
            ->where('type', 'return')
            ->whereNotNull('notes')
            ->orderBy('id')
            ->chunkById(200, function ($movements) {
                foreach ($movements as $movement) {
                    if (! preg_match('/^Refund from sale\s+(.+)$/', (string) $movement->notes, $m)) {
                        continue;
                    }
                    $userId = Sale::query()
                        ->where('business_id', $movement->business_id)
                        ->where('receipt_number', $m[1])
                        ->value('user_id');
                    if ($userId) {
                        $movement->forceFill(['created_by' => $userId])->saveQuietly();
                    }
                }
            });

        $ownerByBusiness = Business::query()
            ->whereNotNull('owner_id')
            ->pluck('owner_id', 'id');

        StockMovement::query()
            ->whereNull('created_by')
            ->orderBy('id')
            ->chunkById(200, function ($movements) use ($ownerByBusiness) {
                foreach ($movements as $movement) {
                    $ownerId = $ownerByBusiness->get($movement->business_id);
                    if ($ownerId) {
                        $movement->forceFill(['created_by' => $ownerId])->saveQuietly();
                    }
                }
            });
    }

    public function down(): void
    {
        // Non-destructive attribution backfill — leave values on rollback.
    }
};
