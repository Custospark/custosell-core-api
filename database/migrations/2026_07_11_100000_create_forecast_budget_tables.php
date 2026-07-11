<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('forecast_budgets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('year');
            $table->string('name');
            $table->string('status', 20)->default('draft'); // draft|active|archived
            $table->timestamps();

            $table->index(['business_id', 'year']);
            $table->index(['business_id', 'status']);
        });

        Schema::create('forecast_budget_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('forecast_budget_id')->constrained('forecast_budgets')->cascadeOnDelete();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('expense_category_id')->nullable()->constrained('expense_categories')->nullOnDelete();
            $table->string('label');
            $table->decimal('amount', 15, 2)->default(0);
            $table->text('justification')->nullable();
            $table->string('zbb_status', 20)->default('draft'); // draft|justified|approved
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['forecast_budget_id', 'sort_order']);
            $table->index(['business_id', 'zbb_status']);
        });

        Schema::create('forecast_snapshots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('forecast_budget_id')->constrained('forecast_budgets')->cascadeOnDelete();
            $table->string('label');
            $table->date('as_of_date');
            $table->json('payload_json');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['business_id', 'forecast_budget_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('forecast_snapshots');
        Schema::dropIfExists('forecast_budget_lines');
        Schema::dropIfExists('forecast_budgets');
    }
};
