<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Link expense records to a personal budget so a budget's actual spend
     * is derived directly from the expense the user already records.
     * Deleting a budget unlinks (sets NULL) rather than deleting records.
     */
    public function up(): void
    {
        Schema::table('expenses', function (Blueprint $table) {
            $table->unsignedBigInteger('budget_id')->nullable()->after('id');
            $table->foreign('budget_id')->references('id')->on('personal_budgets')->nullOnDelete();
            $table->index(['budget_id']);
        });
    }

    public function down(): void
    {
        Schema::table('expenses', function (Blueprint $table) {
            $table->dropForeign(['budget_id']);
            $table->dropIndex(['budget_id']);
            $table->dropColumn('budget_id');
        });
    }
};