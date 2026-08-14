<?php

namespace App\Services\Billing;

use App\Models\Plan;
use Illuminate\Support\Carbon;

class SubscriptionProrationCalculator
{
    public function calculateUpgradeCost(
        Plan $currentPlan,
        Plan $newPlan,
        Carbon $nextBillingDate,
        string $billingCycle = 'monthly',
        ?array $subscriptionPrices = null,
        ?Carbon $trialEndsAt = null,
    ): array {
        $now = Carbon::now()->startOfDay();
        $periodEnd = $nextBillingDate->copy()->startOfDay();
        $periodStart = $billingCycle === 'yearly'
            ? $periodEnd->copy()->subYear()->startOfDay()
            : $periodEnd->copy()->subMonth()->startOfDay();

        $daysInPeriod = max(1, (int) $periodStart->diffInDays($periodEnd));
        // Remaining paid coverage starts when the free trial ends (or now if the
        // trial has already lapsed) - never before, so free trial days are not
        // credited as if the user had paid for them.
        $paidStart = $trialEndsAt && $trialEndsAt->startOfDay()->gt($now)
            ? $trialEndsAt->copy()->startOfDay()
            : $now;
        $daysRemaining = $periodEnd->lte($paidStart)
            ? 0
            : (int) $paidStart->diffInDays($periodEnd);

        $oldPriceUsd = $billingCycle === 'yearly'
            ? (float) ($subscriptionPrices['price_yearly_usd'] ?? $currentPlan->price_yearly_usd ?? 0)
            : (float) ($subscriptionPrices['price_monthly_usd'] ?? $currentPlan->price_monthly_usd ?? 0);

        $newPriceUsd = $billingCycle === 'yearly'
            ? (float) ($newPlan->price_yearly_usd ?? 0)
            : (float) ($newPlan->price_monthly_usd ?? 0);

        // The user has already PAID for the whole remaining window (top-ups can
        // push next_billing_date many periods out, so daysRemaining may exceed
        // daysInPeriod). We keep their paid-through date and charge the pro-rated
        // DIFFERENCE over that full window:
        //   credit = value of the remaining days at the OLD plan price (already paid)
        //   charge = value of the SAME days at the NEW plan price (what they now cost)
        //   due    = the gap between the two, scaled to the remaining fraction.
        $creditUsd = round($oldPriceUsd * ($daysRemaining / $daysInPeriod), 2);
        $chargeUsd = round($newPriceUsd * ($daysRemaining / $daysInPeriod), 2);
        $prorationDueUsd = round(max(0, $chargeUsd - $creditUsd), 2);

        return [
            'proration_due' => $prorationDueUsd,
            'days_remaining' => $daysRemaining,
            'days_in_period' => $daysInPeriod,
            'credit' => $creditUsd,
            'charge' => $chargeUsd,
            'old_price' => $oldPriceUsd,
            'new_price' => $newPriceUsd,
            'old_price_usd' => $oldPriceUsd,
            'new_price_usd' => $newPriceUsd,
            'proration_due_usd' => $prorationDueUsd,
            'credit_usd' => $creditUsd,
            'charge_usd' => $chargeUsd,
        ];
    }
}
