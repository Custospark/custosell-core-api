<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales_reps', function (Blueprint $table) {
            $table->string('payout_frequency', 20)->nullable()->after('payment_method');
            $table->timestamp('next_payout_at')->nullable()->after('payout_frequency');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->string('payout_frequency', 20)->nullable()->after('is_active');
            $table->timestamp('next_payout_at')->nullable()->after('payout_frequency');
        });
    }

    public function down(): void
    {
        Schema::table('sales_reps', function (Blueprint $table) {
            $table->dropColumn(['payout_frequency', 'next_payout_at']);
        });

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['payout_frequency', 'next_payout_at']);
        });
    }
};
