<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('billing_credits', function (Blueprint $table) {
            $table->id();
            $table->morphs('owner');
            $table->unsignedBigInteger('referral_id')->nullable();
            $table->decimal('amount', 14, 2);
            $table->decimal('amount_used', 14, 2)->default(0);
            $table->string('status', 20)->default('available');
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();

            $table->foreign('referral_id')->references('id')->on('referrals')->nullOnDelete();
            $table->index(['owner_type', 'owner_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('billing_credits');
    }
};
