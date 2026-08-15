<?php

declare(strict_types=1);

namespace Tests\Unit\Billing;

use App\Enums\Billing\DiscountType;
use App\Models\Plan;
use App\Models\ReferralCode;
use App\Models\Subscription;

/**
 * Price-agnostic referral pricing expectations.
 *
 * Mirrors ReferralService's base-selection logic exactly so tests stay correct
 * whatever the seeded plan prices are - including whether onboarding fees are
 * set or not. The discount base is the onboarding fee when one exists and is
 * still unpaid, otherwise the monthly price.
 */
trait ReferralPricingExpectations
{
    protected function referralDiscountBase(Plan $plan, ?Subscription $subscription): float
    {
        $monthlyUsd = (float) ($plan->price_monthly_usd ?? 0);
        $onboardingUsd = (float) ($plan->onboarding_fee_usd ?? 0);
        $isOnboarding = $subscription && ! $subscription->onboarding_fee_paid;

        return $isOnboarding && $onboardingUsd > 0 ? $onboardingUsd : $monthlyUsd;
    }

    protected function referralDiscountApplied(ReferralCode $code, Plan $plan, ?Subscription $subscription): float
    {
        $base = $this->referralDiscountBase($plan, $subscription);

        return match ($code->discount_type) {
            DiscountType::PERCENTAGE => round($base * ((float) ($code->discount_value ?? 0) / 100), 2),
            DiscountType::FLAT_AMOUNT => (float) ($code->discount_value ?? 0),
            DiscountType::FREE_MONTH => $base,
        };
    }

    /**
     * What the referee actually paid: the reward base (mirrors markActive's
     * fallback) minus the applied discount, floored at zero.
     */
    protected function referralPaidBase(Plan $plan, ?Subscription $subscription, float $discountApplied): float
    {
        $monthlyUsd = (float) ($plan->price_monthly_usd ?? 0);
        $onboardingUsd = (float) ($plan->onboarding_fee_usd ?? 0);
        $rewardBase = $monthlyUsd;
        if ($subscription && ! $subscription->onboarding_fee_paid && $onboardingUsd > 0) {
            $rewardBase = $onboardingUsd;
        }

        return max(0, round($rewardBase - $discountApplied, 2));
    }

    /** The safe-zone cap: strictly below 50% of the paid base. */
    protected function referralSafeZoneCap(float $paidBase): float
    {
        return round($paidBase * 0.5, 2) - 0.01;
    }
}
