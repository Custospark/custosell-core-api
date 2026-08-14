<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Sales (and their stock movements) created between the locations migration
     * and the sale-creation location wiring have no location. Point them at the
     * business default location so per-branch reporting and receipts work.
     */
    public function up(): void
    {
        DB::transaction(function () {
            $defaults = DB::table('locations')
                ->where('is_default', true)
                ->get(['business_id', 'id']);

            foreach ($defaults as $location) {
                DB::table('sales')
                    ->where('business_id', $location->business_id)
                    ->whereNull('location_id')
                    ->update(['location_id' => $location->id]);

                DB::table('stock_movements')
                    ->where('business_id', $location->business_id)
                    ->whereNull('location_id')
                    ->update(['location_id' => $location->id]);
            }
        });
    }

    public function down(): void
    {
        // Irreversible - the backfill only fills NULLs, so there is nothing safe to undo.
    }
};
