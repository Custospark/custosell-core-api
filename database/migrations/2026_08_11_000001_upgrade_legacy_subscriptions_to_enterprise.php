<?php

use App\Enums\Billing\SubscriptionStatus;
use App\Models\Plan;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Upgrades legacy businesses to the Enterprise plan so they can explore every
 * Custosell module (the original legacy backfill in
 * 2026_07_21_124239_create_subscriptions_for_legacy_businesses granted them the
 * Essential plan, which omits accounting, HR, forecasting, documents, etc.).
 *
 * Targets only the rows the legacy backfill inserted — status TRIAL with
 * trial_used = true and onboarding_fee_paid = true. New registrations never
 * match (they are created with trial_used = false, onboarding_fee_paid = false),
 * and the update is idempotent: once moved to Enterprise the WHERE clause no
 * longer matches. The legacy migration is left untouched.
 */
return new class extends Migration
{
    public function up(): void
    {
        $essential = Plan::where('slug', 'essential')->first();
        $enterprise = Plan::where('slug', 'enterprise')->first()
            ?? Plan::orderByDesc('sort_order')->first();

        if (! $essential || ! $enterprise || $essential->id === $enterprise->id) {
            return;
        }

        DB::table('subscriptions')
            ->where('plan_id', $essential->id)
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
    }

    public function down(): void
    {
        $essential = Plan::where('slug', 'essential')->first()
            ?? Plan::orderBy('sort_order')->first();
        $enterprise = Plan::where('slug', 'enterprise')->first()
            ?? Plan::orderByDesc('sort_order')->first();

        if (! $essential || ! $enterprise || $essential->id === $enterprise->id) {
            return;
        }

        DB::table('subscriptions')
            ->where('plan_id', $enterprise->id)
            ->where('status', SubscriptionStatus::TRIAL->value)
            ->where('trial_used', true)
            ->where('onboarding_fee_paid', true)
            ->update([
                'plan_id' => $essential->id,
                'price_monthly_usd' => $essential->price_monthly_usd,
                'price_yearly_usd' => $essential->price_yearly_usd,
                'onboarding_fee_usd' => $essential->onboarding_fee_usd,
                'updated_at' => Carbon::now(),
            ]);
    }
};
