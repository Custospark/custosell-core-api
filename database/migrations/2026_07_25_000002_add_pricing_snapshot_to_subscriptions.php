<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Columns already added by add_billing_lifecycle_to_subscriptions_table
        if (!Schema::hasColumn('subscriptions', 'price_monthly')) {
            Schema::table('subscriptions', function (Blueprint $table) {
                $table->decimal('price_monthly', 15, 2)->nullable()->after('plan_id');
                $table->decimal('price_yearly', 15, 2)->nullable()->after('price_monthly');
                $table->decimal('onboarding_fee_ugx', 15, 2)->nullable()->after('price_yearly');
            });
        }

        // Backfill existing subscriptions with their plan's current USD prices
        $driver = Schema::getConnection()->getDriverName();
        if ($driver === 'mysql') {
            DB::statement('
                UPDATE subscriptions s
                JOIN plans p ON p.id = s.plan_id
                SET s.price_monthly = p.price_monthly_usd,
                    s.price_yearly = p.price_yearly_usd,
                    s.onboarding_fee_ugx = p.onboarding_fee_usd
            ');
        } else {
            $plans = DB::table('plans')->get(['id', 'price_monthly_usd', 'price_yearly_usd', 'onboarding_fee_usd']);
            foreach ($plans as $plan) {
                DB::table('subscriptions')
                    ->where('plan_id', $plan->id)
                    ->update([
                        'price_monthly' => $plan->price_monthly_usd,
                        'price_yearly' => $plan->price_yearly_usd,
                        'onboarding_fee_ugx' => $plan->onboarding_fee_usd,
                    ]);
            }
        }
    }

    public function down(): void
    {
        Schema::table('subscriptions', function (Blueprint $table) {
            $table->dropColumn(['price_monthly', 'price_yearly', 'onboarding_fee_ugx']);
        });
    }
};
