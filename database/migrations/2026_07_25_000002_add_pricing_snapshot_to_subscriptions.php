<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('subscriptions', function (Blueprint $table) {
            $table->decimal('price_monthly', 15, 2)->nullable()->after('plan_id');
            $table->decimal('price_yearly', 15, 2)->nullable()->after('price_monthly');
            $table->decimal('onboarding_fee_ugx', 15, 2)->nullable()->after('price_yearly');
        });

        // Backfill existing subscriptions with their plan's current prices
        DB::statement('
            UPDATE subscriptions s
            JOIN plans p ON p.id = s.plan_id
            SET s.price_monthly = p.price_monthly,
                s.price_yearly = p.price_yearly,
                s.onboarding_fee_ugx = p.onboarding_fee_ugx
        ');
    }

    public function down(): void
    {
        Schema::table('subscriptions', function (Blueprint $table) {
            $table->dropColumn(['price_monthly', 'price_yearly', 'onboarding_fee_ugx']);
        });
    }
};
