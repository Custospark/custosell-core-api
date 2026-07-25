<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sales_rep_payouts', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('sales_rep_id');
            $table->decimal('amount', 14, 2);
            $table->string('payment_method', 30)->nullable();
            $table->text('notes')->nullable();
            $table->timestamp('paid_at');
            $table->unsignedBigInteger('paid_by')->nullable();
            $table->timestamps();

            $table->foreign('sales_rep_id')
                ->references('id')
                ->on('sales_reps')
                ->onDelete('cascade');

            $table->foreign('paid_by')
                ->references('id')
                ->on('users')
                ->onDelete('set null');

            $table->index('sales_rep_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sales_rep_payouts');
    }
};
