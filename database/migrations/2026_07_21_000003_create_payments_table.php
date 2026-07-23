<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('billing_payments', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('business_id');
            $table->unsignedInteger('subscription_id')->nullable();
            $table->decimal('amount', 14, 2);
            $table->string('currency', 3)->default('UGX');
            $table->string('method', 50)->nullable();
            $table->string('payment_type', 50);
            $table->string('status', 20)->default('pending')->index();
            $table->string('transaction_reference', 255)->nullable();
            $table->string('gateway_name', 50)->nullable();
            $table->string('gateway_transaction_id', 255)->nullable();
            $table->json('gateway_request')->nullable();
            $table->json('gateway_response')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->unsignedBigInteger('approved_by_user_id')->nullable();
            $table->text('rejection_reason')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->foreign('business_id')->references('id')->on('businesses')->cascadeOnDelete();
            $table->foreign('subscription_id')->references('id')->on('subscriptions')->nullOnDelete();
            $table->foreign('approved_by_user_id')->references('id')->on('users')->nullOnDelete();
            $table->index(['business_id', 'status']);
            $table->index(['subscription_id', 'status']);
            $table->index('gateway_transaction_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('billing_payments');
    }
};
