<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('personal_module_subscriptions');
    }

    public function down(): void
    {
        Schema::create('personal_module_subscriptions', function ($table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('module_slug');
            $table->string('status')->default('pending');
            $table->string('billing_cycle')->default('monthly');
            $table->decimal('price_usd', 10, 2)->default(0);
            $table->timestamp('current_period_start')->nullable();
            $table->timestamp('current_period_end')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamps();
        });
    }
};
