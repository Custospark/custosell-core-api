<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('personal_module_subscriptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('module_slug', 50);
            $table->string('status', 20)->default('active');
            $table->string('billing_cycle', 10)->default('monthly');
            $table->decimal('price_usd', 10, 2);
            $table->timestamp('current_period_start')->nullable();
            $table->timestamp('current_period_end')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'module_slug']);
        });

        Schema::table('billing_payments', function (Blueprint $table) {
            $table->foreignId('user_id')->nullable()->after('subscription_id')->constrained()->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('billing_payments', function (Blueprint $table) {
            $table->dropForeign(['user_id']);
            $table->dropColumn('user_id');
        });
        Schema::dropIfExists('personal_module_subscriptions');
    }
};
