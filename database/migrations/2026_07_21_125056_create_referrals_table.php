<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('referrals', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('referral_code_id');
            $table->unsignedInteger('subscription_id')->nullable();
            $table->unsignedBigInteger('referred_business_id');
            $table->string('status', 20)->default('pending');
            $table->decimal('discount_applied', 14, 2)->nullable();
            $table->decimal('reward_amount', 14, 2)->nullable();
            $table->boolean('reward_paid')->default(false);
            $table->datetime('converted_at')->nullable();
            $table->timestamps();

            $table->foreign('referral_code_id')->references('id')->on('referral_codes')->cascadeOnDelete();
            $table->foreign('subscription_id')->references('id')->on('subscriptions')->nullOnDelete();
            $table->foreign('referred_business_id')->references('id')->on('businesses')->cascadeOnDelete();
            $table->index(['referral_code_id', 'subscription_id', 'referred_business_id', 'status'], 'referrals_lookup_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('referrals');
    }
};
