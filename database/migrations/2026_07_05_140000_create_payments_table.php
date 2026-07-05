<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('business_id');
            $table->string('payable_type', 50);
            $table->unsignedBigInteger('payable_id');
            $table->string('receipt_number', 50);
            $table->decimal('amount', 12, 2);
            $table->string('payment_method', 30);
            $table->decimal('balance_after', 12, 2);
            $table->unsignedBigInteger('recorded_by')->nullable();
            $table->timestamp('paid_at');
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->foreign('business_id')->references('id')->on('businesses')->cascadeOnDelete();
            $table->foreign('recorded_by')->references('id')->on('users')->nullOnDelete();
            $table->unique(['business_id', 'receipt_number']);
            $table->index(['payable_type', 'payable_id']);
            $table->index(['business_id', 'paid_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payments');
    }
};
