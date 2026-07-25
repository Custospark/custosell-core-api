<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales_reps', function (Blueprint $table) {
            $table->decimal('commission_rate', 14, 2)->default(0)->change();
        });
    }

    public function down(): void
    {
        Schema::table('sales_reps', function (Blueprint $table) {
            $table->decimal('commission_rate', 5, 2)->default(0)->change();
        });
    }
};
