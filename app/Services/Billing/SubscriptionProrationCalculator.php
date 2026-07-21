<?php

namespace App\Services\Billing;

use App\Models\Plan;
use Illuminate\Support\Carbon;

class SubscriptionProrationCalculator
{
    public function calculateUpgradeCost(Plan $currentPlan, Plan $newPlan, Carbon $nextBillingDate, string $billingCycle = 'monthly'): array
    {
        $now = Carbon::now()->startOfDay();
        $periodEnd = $nextBillingDate->copy()->startOfDay();
        $periodStart = $now->copy()->subMonth()->startOfDay();

        $daysInPeriod = max(1, (int) $periodStart->diffInDays($periodEnd));
        $daysRemaining = $periodEnd->lte($now)
            ? 0
            : (int) $now->diffInDays($periodEnd);

        $oldPrice = $billingCycle === 'yearly'
            ? (float) ($currentPlan->price_yearly ?? 0)
            : (float) ($currentPlan->price_monthly ?? 0);

        $newPrice = $billingCycle === 'yearly'
            ? (float) ($newPlan->price_yearly ?? 0)
            : (float) ($newPlan->price_monthly ?? 0);

        $oldPriceUsd = $billingCycle === 'yearly'
            ? (float) ($currentPlan->price_yearly_usd ?? 0)
            : (float) ($currentPlan->price_monthly_usd ?? 0);

        $newPriceUsd = $billingCycle === 'yearly'
            ? (float) ($newPlan->price_yearly_usd ?? 0)
            : (float) ($newPlan->price_monthly_usd ?? 0);

        $credit = round($oldPrice * ($daysRemaining / $daysInPeriod), 2);
        $charge = round($newPrice * ($daysRemaining / $daysInPeriod), 2);

        $prorationDue = round(max(0, $charge - $credit), 2);

        return [
            'proration_due' => $prorationDue,
            'days_remaining' => $daysRemaining,
            'days_in_period' => $daysInPeriod,
            'credit' => $credit,
            'charge' => $charge,
            'old_price' => $oldPrice,
            'new_price' => $newPrice,
            'old_price_usd' => $oldPriceUsd,
            'new_price_usd' => $newPriceUsd,
        ];
    }
}
