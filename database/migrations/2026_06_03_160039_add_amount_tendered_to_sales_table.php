<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales', function (Blueprint $table) {
            $table->decimal('amount_tendered', 12, 2)->nullable()->after('total_amount');
            $table->decimal('change_given', 12, 2)->nullable()->after('amount_tendered');
        });
    }

    public function down(): void
    {
        Schema::table('sales', function (Blueprint $table) {
            $table->dropColumn(['amount_tendered', 'change_given']);
        });
    }
};
