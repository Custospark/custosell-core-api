<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Personal budgets are named spending goals a personal account can create
     * many of (e.g. "Groceries", "June holiday", "House savings"). Income and
     * expense records link to a budget via budget_id so each budget's actuals
     * stay in sync with what the user already records.
     */
    public function up(): void
    {
        Schema::create('personal_budgets', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('business_id');
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('name');
            $table->string('description')->nullable();
            $table->decimal('planned_amount', 15, 2)->default(0);
            $table->string('period_start')->nullable();
            $table->string('period_end')->nullable();
            $table->string('status')->default('active');
            $table->timestamps();

            $table->foreign('business_id')->references('id')->on('businesses')->cascadeOnDelete();
            $table->foreign('user_id')->references('id')->on('users')->nullOnDelete();
            $table->index(['business_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('personal_budgets');
    }
};