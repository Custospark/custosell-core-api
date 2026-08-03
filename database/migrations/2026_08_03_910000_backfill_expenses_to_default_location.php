<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('expenses', 'location_id')) {
            return;
        }

        DB::transaction(function () {
            $defaults = DB::table('locations')
                ->where('is_default', true)
                ->where('is_active', true)
                ->get(['id', 'business_id']);

            foreach ($defaults as $location) {
                DB::table('expenses')
                    ->where('business_id', $location->business_id)
                    ->whereNull('location_id')
                    ->update(['location_id' => $location->id]);
            }

            // Any leftover expense without a default branch keeps location_id null.
        });
    }

    public function down(): void
    {
        // Data backfill; non-destructive by design.
    }
};