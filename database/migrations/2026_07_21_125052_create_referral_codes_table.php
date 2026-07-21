<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('referral_codes', function (Blueprint $table) {
            $table->id();
            $table->string('owner_type', 32);
            $table->unsignedBigInteger('owner_business_id')->nullable();
            $table->unsignedBigInteger('owner_user_id')->nullable();
            $table->string('code', 64)->unique();
            $table->string('discount_type', 20);
            $table->decimal('discount_value', 14, 2)->nullable();
            $table->unsignedTinyInteger('discount_duration_months')->default(1);
            $table->string('reward_type', 20)->default('free_month');
            $table->decimal('reward_value', 14, 2)->nullable();
            $table->unsignedInteger('max_uses')->nullable();
            $table->unsignedInteger('used_count')->default(0);
            $table->boolean('is_active')->default(true);
            $table->datetime('expires_at')->nullable();
            $table->timestamps();

            $table->foreign('owner_business_id')->references('id')->on('businesses')->cascadeOnDelete();
            $table->foreign('owner_user_id')->references('id')->on('users')->cascadeOnDelete();
            $table->index(['owner_type', 'owner_business_id']);
            $table->index(['owner_type', 'owner_user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('referral_codes');
    }
};
