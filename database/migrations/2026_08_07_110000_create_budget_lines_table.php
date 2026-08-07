<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A budget plan / shopping list: priced line items (name, quantity, unit
     * price). The budget's planned_amount auto-totals from these lines, and a
     * user can convert a purchased line into a real expense.
     */
    public function up(): void
    {
        Schema::create('budget_lines', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('personal_budget_id');
            $table->string('item_name');
            $table->unsignedInteger('quantity')->default(1);
            $table->decimal('unit_price', 15, 2)->default(0);
            $table->decimal('line_total', 15, 2)->default(0);
            $table->boolean('purchased')->default(false);
            $table->unsignedBigInteger('expense_id')->nullable();
            $table->timestamps();

            $table->foreign('personal_budget_id')->references('id')->on('personal_budgets')->cascadeOnDelete();
            $table->foreign('expense_id')->references('id')->on('expenses')->nullOnDelete();
            $table->index(['personal_budget_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('budget_lines');
    }
};