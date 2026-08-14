<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::transaction(function () {
            $defaults = DB::table('locations')
                ->where('is_default', true)
                ->where('is_active', true)
                ->get(['business_id', 'id']);

            foreach ($defaults as $location) {
                DB::table('shifts')
                    ->where('business_id', $location->business_id)
                    ->whereNull('location_id')
                    ->update(['location_id' => $location->id]);
            }
        });
    }

    public function down(): void
    {
        // Irreversible - the backfill only fills NULLs.
    }
};