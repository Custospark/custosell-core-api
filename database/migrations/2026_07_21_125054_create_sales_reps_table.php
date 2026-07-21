<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sales_reps', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id')->unique();
            $table->unsignedBigInteger('referral_code_id')->unique();
            $table->decimal('commission_rate', 5, 2)->default(0);
            $table->string('commission_type', 20)->default('percentage');
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
            $table->foreign('referral_code_id')->references('id')->on('referral_codes')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sales_reps');
    }
};
