<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('subscriptions', function (Blueprint $table) {
            $table->string('billing_cycle', 20)->default('monthly');
            $table->dateTime('next_billing_date')->nullable();
            $table->dateTime('grace_period_ends_at')->nullable();
            $table->dateTime('suspended_at')->nullable();
            $table->dateTime('approved_at')->nullable();
            $table->unsignedBigInteger('approved_by_user_id')->nullable();
            $table->boolean('onboarding_fee_paid')->default(false);
            $table->text('notes')->nullable();
            $table->json('metadata')->nullable();
            $table->softDeletes();

            $table->foreign('approved_by_user_id')->references('id')->on('users')->nullOnDelete();
            $table->index('next_billing_date');
            $table->index('grace_period_ends_at');
        });
    }

    public function down(): void
    {
        Schema::table('subscriptions', function (Blueprint $table) {
            $table->dropForeign(['approved_by_user_id']);
            $table->dropIndex(['next_billing_date']);
            $table->dropIndex(['grace_period_ends_at']);
            $table->dropSoftDeletes();
            $table->dropColumn([
                'billing_cycle',
                'next_billing_date',
                'grace_period_ends_at',
                'suspended_at',
                'approved_at',
                'approved_by_user_id',
                'onboarding_fee_paid',
                'notes',
                'metadata',
            ]);
        });
    }
};
