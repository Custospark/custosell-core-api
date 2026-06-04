<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('expense_categories', function (Blueprint $table) {
            $table->decimal('budget_amount', 12, 2)->nullable()->after('sort_order');
            $table->string('budget_period', 20)->nullable()->after('budget_amount');
        });
    }

    public function down(): void
    {
        Schema::table('expense_categories', function (Blueprint $table) {
            $table->dropColumn(['budget_amount', 'budget_period']);
        });
    }
};
