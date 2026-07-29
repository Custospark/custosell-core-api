<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('plans', function (Blueprint $table) {
            $table->dropColumn(['price_monthly', 'price_yearly', 'onboarding_fee_ugx']);
        });
    }

    public function down(): void
    {
        Schema::table('plans', function (Blueprint $table) {
            $table->decimal('price_monthly', 10, 2)->default(0.00);
            $table->decimal('price_yearly', 10, 2)->nullable();
            $table->decimal('onboarding_fee_ugx', 14, 2)->default(0.00);
        });
    }
};
