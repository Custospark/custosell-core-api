<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('plans', function (Blueprint $table) {
            // Flat annual maintenance fee per tier (UGX), shown on quotations.
            $table->decimal('maintenance_fee_ugx', 14, 2)->default(0)->after('onboarding_fee_usd');
        });
    }

    public function down(): void
    {
        Schema::table('plans', function (Blueprint $table) {
            $table->dropColumn('maintenance_fee_ugx');
        });
    }
};
