<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('locations') || !Schema::hasTable('businesses')) {
            return;
        }

        DB::transaction(function () {
            $now = now();

            $businessRows = DB::table('businesses')
                ->select('id', 'country')
                ->orderBy('id')
                ->get();

            foreach ($businessRows as $business) {
                $businessId = (int) $business->id;

                $default = DB::table('locations')
                    ->where('business_id', $businessId)
                    ->where('is_default', true)
                    ->whereNull('deleted_at')
                    ->first();

                if (!$default) {
                    $defaultId = DB::table('locations')->insertGetId([
                        'business_id' => $businessId,
                        'name' => 'Main Branch',
                        'code' => 'MAIN',
                        'country' => $business->country ?? 'UG',
                        'is_default' => true,
                        'is_active' => true,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]);

                    $default = (object) ['id' => $defaultId];
                }

                $locationId = (int) $default->id;

                foreach (['users', 'shifts', 'sales', 'stock_movements', 'invoices', 'orders'] as $table) {
                    if (Schema::hasTable($table) && Schema::hasColumn($table, 'location_id')) {
                        DB::table($table)
                            ->where('business_id', $businessId)
                            ->whereNull('location_id')
                            ->update(['location_id' => $locationId]);
                    }
                }

                $userIds = DB::table('users')->where('business_id', $businessId)->pluck('id');
                foreach ($userIds as $userId) {
                    DB::table('location_user')->updateOrInsert(
                        ['location_id' => $locationId, 'user_id' => $userId],
                        ['business_id' => $businessId, 'created_at' => $now, 'updated_at' => $now],
                    );
                }

                $products = DB::table('products')
                    ->where('business_id', $businessId)
                    ->get(['id', 'stock_quantity', 'low_stock_threshold']);

                foreach ($products as $product) {
                    DB::table('location_product')->updateOrInsert(
                        ['location_id' => $locationId, 'product_id' => $product->id],
                        [
                            'business_id' => $businessId,
                            'stock_quantity' => $product->stock_quantity,
                            'low_stock_threshold' => $product->low_stock_threshold,
                            'created_at' => $now,
                            'updated_at' => $now,
                        ],
                    );
                }
            }
        });
    }

    public function down(): void
    {
        // Data backfill; non-destructive by design.
    }
};
