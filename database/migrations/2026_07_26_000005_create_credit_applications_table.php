<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('credit_applications', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('credit_id');
            $table->unsignedInteger('subscription_id');
            $table->unsignedBigInteger('billing_payment_id')->nullable();
            $table->decimal('amount_applied', 14, 2);
            $table->timestamp('applied_at');
            $table->timestamps();

            $table->foreign('credit_id')->references('id')->on('billing_credits')->cascadeOnDelete();
            $table->foreign('subscription_id')->references('id')->on('subscriptions')->cascadeOnDelete();
            $table->foreign('billing_payment_id')->references('id')->on('billing_payments')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('credit_applications');
    }
};
