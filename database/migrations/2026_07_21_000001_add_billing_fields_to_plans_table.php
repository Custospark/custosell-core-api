<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('plans', function (Blueprint $table) {
            $table->decimal('price_monthly_usd', 10, 2)->default(0.00);
            $table->decimal('price_yearly_usd', 10, 2)->nullable();
            $table->decimal('onboarding_fee_ugx', 14, 2)->default(0.00);
            $table->decimal('onboarding_fee_usd', 10, 2)->default(0.00);
            $table->tinyInteger('trial_days')->unsigned()->default(14);
            $table->string('billing_cycle', 20)->default('monthly');
            $table->boolean('is_popular')->default(false);
            $table->json('metadata')->nullable();
            $table->index('is_active');
        });
    }

    public function down(): void
    {
        Schema::table('plans', function (Blueprint $table) {
            $table->dropIndex(['is_active']);
            $table->dropColumn([
                'price_monthly_usd',
                'price_yearly_usd',
                'onboarding_fee_ugx',
                'onboarding_fee_usd',
                'trial_days',
                'billing_cycle',
                'is_popular',
                'metadata',
            ]);
        });
    }
};
