<?php

use App\Enums\Billing\ReferralCodeOwnerType;
use App\Enums\Billing\RewardType;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Default reward for a NEW referral code is the normal program (15%
        // percentage reward) — not free_month. `free_month` as a schema default
        // silently granted a full paid-base reward credit to the referrer for any
        // code created without an explicit reward_type (backfill, admin, sales
        // rep, campaign). That leak was already fixed for CAMPAIGN codes in code;
        // this fixes the value that flows into every business-owner code.
        Schema::table('referral_codes', function (Blueprint $table) {
            $table->string('reward_type', 20)->default(RewardType::PERCENTAGE->value)->change();
        });

        // Repair existing BUSINESS-owner codes that inherited the buggy
        // free_month default with no reward value: normalize them to the standard
        // program (percentage / 15%) so a used code pays 15% of what the referee
        // actually paid — never the full paid amount.
        DB::table('referral_codes')
            ->where('owner_type', ReferralCodeOwnerType::BUSINESS->value)
            ->where('reward_type', RewardType::FREE_MONTH->value)
            ->whereNull('reward_value')
            ->update([
                'reward_type' => RewardType::PERCENTAGE->value,
                'reward_value' => 15,
            ]);
    }

    public function down(): void
    {
        // Best-effort revert: restore the original default and undo the value
        // normalization for codes that matched the auto-generated signature.
        Schema::table('referral_codes', function (Blueprint $table) {
            $table->string('reward_type', 20)->default(RewardType::FREE_MONTH->value)->change();
        });

        DB::table('referral_codes')
            ->where('owner_type', ReferralCodeOwnerType::BUSINESS->value)
            ->where('reward_type', RewardType::PERCENTAGE->value)
            ->where('reward_value', 15)
            ->update([
                'reward_type' => RewardType::FREE_MONTH->value,
                'reward_value' => null,
            ]);
    }
};