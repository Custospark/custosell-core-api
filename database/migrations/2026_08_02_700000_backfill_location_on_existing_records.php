<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Sales, stock movements, users, invoices and orders created before the
     * per-transaction location wiring have NULL location_id. Point them all at
     * the business default location so per-branch reporting and receipts work.
     */
    public function up(): void
    {
        DB::transaction(function () {
            $defaults = DB::table('locations')
                ->where('is_default', true)
                ->where('is_active', true)
                ->get(['business_id', 'id']);

            foreach ($defaults as $location) {
                $tables = ['sales', 'stock_movements', 'invoices', 'orders', 'users'];

                foreach ($tables as $table) {
                    if (!Schema::hasTable($table) || !Schema::hasColumn($table, 'location_id')) {
                        continue;
                    }
                    DB::table($table)
                        ->where('business_id', $location->business_id)
                        ->whereNull('location_id')
                        ->update(['location_id' => $location->id]);
                }
            }
        });
    }

    public function down(): void
    {
        // Irreversible — the backfill only fills NULLs, so there is nothing safe to undo.
    }
};