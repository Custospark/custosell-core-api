<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Re-open onboarding for anyone who never truly completed it
 * (earlier migration auto-skipped existing tenants).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('users')
            ->whereNull('tour_completed_at')
            ->update([
                'tour_skipped_at' => null,
                'tour_step' => 0,
            ]);

        DB::table('businesses')
            ->whereNull('intent_completed_at')
            ->update([
                'intent_skipped_at' => null,
            ]);
    }

    public function down(): void
    {
        // Irreversible product correction — no-op.
    }
};
