<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('businesses', function (Blueprint $table) {
            $table->string('primary_intent', 64)->nullable()->after('supply_headline');
            $table->string('secondary_intent', 64)->nullable()->after('primary_intent');
            $table->timestamp('intent_completed_at')->nullable()->after('secondary_intent');
            $table->timestamp('intent_skipped_at')->nullable()->after('intent_completed_at');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->unsignedSmallInteger('tour_step')->default(0)->after('modules');
            $table->timestamp('tour_completed_at')->nullable()->after('tour_step');
            $table->timestamp('tour_skipped_at')->nullable()->after('tour_completed_at');
        });

        // Existing tenants: do not force intent/tour on next login.
        $now = now();
        DB::table('businesses')
            ->whereNull('intent_completed_at')
            ->whereNull('intent_skipped_at')
            ->update(['intent_skipped_at' => $now]);

        DB::table('users')
            ->whereNull('tour_completed_at')
            ->whereNull('tour_skipped_at')
            ->update(['tour_skipped_at' => $now]);
    }

    public function down(): void
    {
        Schema::table('businesses', function (Blueprint $table) {
            $table->dropColumn([
                'primary_intent',
                'secondary_intent',
                'intent_completed_at',
                'intent_skipped_at',
            ]);
        });

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn([
                'tour_step',
                'tour_completed_at',
                'tour_skipped_at',
            ]);
        });
    }
};
