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
            $table->decimal('price_monthly_usd', 15, 2)->nullable()->after('price_monthly');
            $table->decimal('price_yearly_usd', 15, 2)->nullable()->after('price_yearly');
            $table->decimal('onboarding_fee_usd', 15, 2)->nullable()->after('onboarding_fee_ugx');
        });

        $subscriptions = DB::table('subscriptions')->whereNull('price_monthly_usd')->get();
        foreach ($subscriptions as $sub) {
            $plan = DB::table('plans')->where('id', $sub->plan_id)->first();
            if ($plan) {
                DB::table('subscriptions')->where('id', $sub->id)->update([
                    'price_monthly_usd' => $plan->price_monthly_usd,
                    'price_yearly_usd' => $plan->price_yearly_usd,
                    'onboarding_fee_usd' => $plan->onboarding_fee_usd,
                ]);
            }
        }
    }

    public function down(): void
    {
        Schema::table('subscriptions', function (Blueprint $table) {
            $table->dropColumn(['price_monthly_usd', 'price_yearly_usd', 'onboarding_fee_usd']);
        });
    }
};
