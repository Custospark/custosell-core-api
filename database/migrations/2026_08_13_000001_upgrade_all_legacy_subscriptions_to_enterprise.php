<?php

use App\Enums\Billing\SubscriptionStatus;
use App\Models\Plan;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Upgrades ALL legacy business subscriptions to the Enterprise plan.
 *
 * The original legacy backfill (2026_07_21_124239) assigned plan_id pointing at
 * whatever plan existed first by sort_order at the time. In production that was
 * the STALE 'free' plan (id 1) from the original Free/Pro/Premium seed set —
 * NOT 'essential' as the dev database had. The prior enterprise upgrade
 * (2026_08_11_000001) only matched plan_id = essential, so it upgraded zero
 * legacy subscriptions in production, leaving every legacy business on 'Free'.
 *
 * This migration matches the legacy backfill signature (status TRIAL +
 * trial_used = true + onboarding_fee_paid = true) regardless of which plan the
 * subscription currently points at, and moves them all to Enterprise. New
 * registrations never match (they are created with trial_used = false,
 * onboarding_fee_paid = false). Idempotent: once on Enterprise the legacy
 * filter no longer changes them.
 *
 * It also deactivates the stale Free/Pro/Premium plans so they stop appearing
 * in the active plans list (Plans tab / dropdown) after all legacy
 * subscriptions have been migrated off them.
 */
return new class extends Migration
{
    public function up(): void
    {
        $enterprise = Plan::where('slug', 'enterprise')->first()
            ?? Plan::where('type', 'business')->orderByDesc('sort_order')->first();

        if (! $enterprise) {
            return;
        }

        DB::table('subscriptions')
            ->where('status', SubscriptionStatus::TRIAL->value)
            ->where('trial_used', true)
            ->where('onboarding_fee_paid', true)
            ->update([
                'plan_id' => $enterprise->id,
                'price_monthly_usd' => $enterprise->price_monthly_usd,
                'price_yearly_usd' => $enterprise->price_yearly_usd,
                'onboarding_fee_usd' => $enterprise->onboarding_fee_usd,
                'updated_at' => Carbon::now(),
            ]);

        Plan::whereIn('slug', ['free', 'pro', 'premium'])
            ->update(['is_active' => false, 'updated_at' => Carbon::now()]);
    }

    public function down(): void
    {
        Plan::whereIn('slug', ['free', 'pro', 'premium'])
            ->update(['is_active' => true, 'updated_at' => Carbon::now()]);

        $enterprise = Plan::where('slug', 'enterprise')->first();
        $legacy = Plan::whereIn('slug', ['free', 'essential'])->first()
            ?? Plan::orderBy('sort_order')->first();

        if (! $enterprise || ! $legacy || $enterprise->id === $legacy->id) {
            return;
        }

        DB::table('subscriptions')
            ->where('plan_id', $enterprise->id)
            ->where('status', SubscriptionStatus::TRIAL->value)
            ->where('trial_used', true)
            ->where('onboarding_fee_paid', true)
            ->update([
                'plan_id' => $legacy->id,
                'price_monthly_usd' => $legacy->price_monthly_usd,
                'price_yearly_usd' => $legacy->price_yearly_usd,
                'onboarding_fee_usd' => $legacy->onboarding_fee_usd,
                'updated_at' => Carbon::now(),
            ]);
    }
};
