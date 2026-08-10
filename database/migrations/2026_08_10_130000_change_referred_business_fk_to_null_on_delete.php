<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Referrals are an earnings ledger for the referrer. When a referred business is
 * deleted (platform hard delete), the referral row must survive so the referrer's
 * earned reward history is not erased — otherwise earnings reset to zero while
 * payouts stay, producing a negative "Bal." in the referrer's account.
 *
 * The business reference is retained as a soft pointer (nulled on delete) only
 * for display; the reward_amount / status / reward_paid history is preserved.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('referrals', function (Blueprint $table) {
            $table->unsignedBigInteger('referred_business_id')->nullable()->change();
            $table->dropForeign(['referred_business_id']);
            $table->foreign('referred_business_id')->references('id')->on('businesses')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('referrals', function (Blueprint $table) {
            $table->dropForeign(['referred_business_id']);
            $table->foreign('referred_business_id')->references('id')->on('businesses')->cascadeOnDelete();
            $table->unsignedBigInteger('referred_business_id')->nullable(false)->change();
        });
    }
};