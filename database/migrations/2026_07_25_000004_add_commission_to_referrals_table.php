<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('referrals', function (Blueprint $table) {
            $table->decimal('commission_earned', 14, 2)->nullable()->after('reward_amount');
            $table->boolean('commission_paid')->default(false)->after('commission_earned');
        });
    }

    public function down(): void
    {
        Schema::table('referrals', function (Blueprint $table) {
            $table->dropColumn(['commission_earned', 'commission_paid']);
        });
    }
};
