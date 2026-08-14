<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Staff created before the locations feature was fully wired may have a NULL
        // location_id and no location_user pivot rows. Assign them to their business's
        // default (or first) branch so per-branch sale/stock/shift scoping still works.
        DB::transaction(function () {
            $businessIds = DB::table('businesses')->pluck('id');

            foreach ($businessIds as $businessId) {
                $defaultLocationId = DB::table('locations')
                    ->where('business_id', $businessId)
                    ->orderByDesc('is_default')
                    ->orderBy('id')
                    ->value('id');

                if (!$defaultLocationId) {
                    continue;
                }

                $userIds = DB::table('users')
                    ->where('business_id', $businessId)
                    ->whereNull('location_id')
                    ->pluck('id');

                if ($userIds->isEmpty()) {
                    continue;
                }

                DB::table('users')
                    ->whereIn('id', $userIds)
                    ->update(['location_id' => $defaultLocationId]);

                $now = now();
                foreach ($userIds as $userId) {
                    $exists = DB::table('location_user')
                        ->where('user_id', $userId)
                        ->where('location_id', $defaultLocationId)
                        ->exists();

                    if (!$exists) {
                        DB::table('location_user')->insert([
                            'business_id' => $businessId,
                            'location_id' => $defaultLocationId,
                            'user_id' => $userId,
                            'created_at' => $now,
                            'updated_at' => $now,
                        ]);
                    }
                }
            }
        });
    }

    public function down(): void
    {
        // Intentionally no-op - backfill is a forward data correction.
    }
};
