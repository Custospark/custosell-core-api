<?php

use App\Enums\Billing\SubscriptionStatus;
use App\Models\Plan;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Resets the trial clock for the legacy subscriptions upgraded to Enterprise.
 *
 * The upgrade migration (2026_08_13_000001) moves all legacy subscriptions to
 * the Enterprise plan but leaves trial_ends_at at the value set by the original
 * legacy backfill (now + 30 days from 2026-07-21), which has mostly already
 * elapsed. This resets their Enterprise trial so it starts fresh from the
 * moment this migration runs: trial_ends_at = now + the Enterprise plan's
 * trial_days (falling back to 30 days if the plan has none).
 *
 * Matches the same legacy signature (status TRIAL + trial_used + fee_paid) and
 * is idempotent: it only touches rows still in TRIAL status and still on the
 * Enterprise plan.
 */
return new class extends Migration
{
    public function up(): void
    {
        $enterprise = Plan::where('slug', 'enterprise')->first();
        if (! $enterprise) {
            return;
        }

        $trialEnd = Carbon::now()->addDays((int) ($enterprise->trial_days ?? 30));

        DB::table('subscriptions')
            ->where('plan_id', $enterprise->id)
            ->where('status', SubscriptionStatus::TRIAL->value)
            ->where('trial_used', true)
            ->where('onboarding_fee_paid', true)
            ->update([
                'trial_ends_at' => $trialEnd,
                'updated_at' => Carbon::now(),
            ]);
    }

    public function down(): void
    {
        // No reliable way to restore original trial end dates; no-op.
    }
};
