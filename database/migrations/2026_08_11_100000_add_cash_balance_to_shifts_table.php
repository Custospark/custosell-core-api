<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shifts', function (Blueprint $table) {
            $table->decimal('opening_balance', 12, 2)->default(0)->after('clock_out');
            $table->decimal('counted_cash', 12, 2)->nullable()->after('opening_balance');
        });
    }

    public function down(): void
    {
        Schema::table('shifts', function (Blueprint $table) {
            $table->dropColumn(['counted_cash', 'opening_balance']);
        });
    }
};
