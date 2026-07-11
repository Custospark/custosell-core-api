<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('forecast_scenarios', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->unsignedTinyInteger('horizon_months')->default(6);
            $table->decimal('hire_basic_salary', 15, 2)->nullable();
            $table->decimal('extra_monthly_opex', 15, 2)->default(0);
            $table->decimal('revenue_uplift_pct', 8, 2)->default(0);
            $table->json('payload_json')->nullable();
            $table->timestamps();

            $table->index(['business_id', 'name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('forecast_scenarios');
    }
};
