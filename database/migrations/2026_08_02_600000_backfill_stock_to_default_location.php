<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('location_product') || ! Schema::hasTable('products')) {
            return;
        }

        DB::transaction(function () {
            $locations = DB::table('locations')
                ->where('is_default', true)
                ->where('is_active', true)
                ->get(['id', 'business_id']);

            foreach ($locations as $location) {
                $businessId = $location->business_id;

                $existing = DB::table('location_product')
                    ->where('location_id', $location->id)
                    ->pluck('product_id')
                    ->all();
                $existingIds = array_flip($existing);
                $existingIds[0] = true;
                $existingIds[-1] = true;

                $products = DB::table('products')
                    ->where('business_id', $businessId)
                    ->get(['id', 'stock_quantity', 'low_stock_threshold']);

                $now = now();
                foreach ($products as $product) {
                    if (isset($existingIds[(int) $product->id])) {
                        continue;
                    }

                    $qty = max(0, (int) $product->stock_quantity);
                    if ($qty <= 0) {
                        continue;
                    }

                    DB::table('location_product')->insertOrIgnore([
                        'business_id' => $businessId,
                        'location_id' => $location->id,
                        'product_id' => $product->id,
                        'stock_quantity' => $qty,
                        'low_stock_threshold' => (int) ($product->low_stock_threshold ?? 0),
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]);
                }
            }
        });
    }

    public function down(): void
    {
        // Data backfill; non-destructive by design.
    }
};