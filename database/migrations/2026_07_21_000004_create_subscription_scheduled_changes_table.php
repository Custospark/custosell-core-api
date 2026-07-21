<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('subscription_scheduled_changes', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('subscription_id');
            $table->unsignedBigInteger('business_id');
            $table->string('change_type', 32);
            $table->unsignedBigInteger('from_plan_id')->nullable();
            $table->unsignedBigInteger('to_plan_id')->nullable();
            $table->timestamp('effective_at');
            $table->string('status', 16)->default('pending');
            $table->decimal('proration_amount', 14, 2)->nullable();
            $table->unsignedBigInteger('requested_by_user_id')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->foreign('subscription_id')->references('id')->on('subscriptions')->cascadeOnDelete();
            $table->foreign('business_id')->references('id')->on('businesses')->cascadeOnDelete();
            $table->foreign('from_plan_id')->references('id')->on('plans')->nullOnDelete();
            $table->foreign('to_plan_id')->references('id')->on('plans')->nullOnDelete();
            $table->foreign('requested_by_user_id')->references('id')->on('users')->nullOnDelete();
            $table->index(['subscription_id', 'status']);
            $table->index(['business_id', 'status']);
            $table->index('effective_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('subscription_scheduled_changes');
    }
};
