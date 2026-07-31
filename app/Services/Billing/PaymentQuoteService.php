<?php

namespace App\Services\Billing;

use App\Models\Subscription;
use App\Repositories\Contracts\PlanRepositoryInterface;
use App\Repositories\Contracts\SubscriptionRepositoryInterface;

class PaymentQuoteService
{
    public function __construct(
        protected SubscriptionRepositoryInterface $subscriptionRepo,
        protected PlanRepositoryInterface $planRepo,
        protected SubscriptionProrationCalculator $prorationCalculator,
    ) {}

    public function getQuote(Subscription $subscription, int $toPlanId, ?string $billingCycleOverride = null): array
    {
        $currentPlan = $subscription->plan;
        if (!$currentPlan) {
            throw new \RuntimeException('Current plan not found on subscription');
        }

        $newPlan = $this->planRepo->find($toPlanId);
        if (!$newPlan) {
            throw new \RuntimeException('Target plan not found');
        }

        $billingCycle = $subscription->billing_cycle ?? 'monthly';
        $targetCycle = $billingCycleOverride ?? $billingCycle;
        $nextBillingDate = $subscription->next_billing_date
            ?? $subscription->ends_at
            ?? now()->addMonth();

        $subscriptionPrices = [
            'price_monthly_usd' => $subscription->price_monthly_usd ?? $currentPlan->price_monthly_usd,
            'price_yearly_usd' => $subscription->price_yearly_usd ?? $currentPlan->price_yearly_usd,
        ];

        $proration = $this->prorationCalculator->calculateUpgradeCost(
            $currentPlan,
            $newPlan,
            $nextBillingDate,
            $billingCycle,
            $subscriptionPrices,
        );

        if ($targetCycle !== $billingCycle) {
            $newPriceUsd = $targetCycle === 'yearly'
                ? (float) ($newPlan->price_yearly_usd ?? 0)
                : (float) ($newPlan->price_monthly_usd ?? 0);

            $chargeUsd = $newPriceUsd;
            $prorationDueUsd = round(max(0, $chargeUsd - $proration['credit_usd']), 2);

            $proration['new_price'] = $newPriceUsd;
            $proration['new_price_usd'] = $newPriceUsd;
            $proration['charge'] = $chargeUsd;
            $proration['charge_usd'] = $chargeUsd;
            $proration['proration_due'] = $prorationDueUsd;
            $proration['proration_due_usd'] = $prorationDueUsd;
        }

        // Trial subscriptions carry no unused credit: the user has paid nothing,
        // so subscribing or upgrading charges the full target plan price.
        if ($subscription->isOnTrial()) {
            $fullPriceUsd = $targetCycle === 'yearly'
                ? (float) ($newPlan->price_yearly_usd ?? 0)
                : (float) ($newPlan->price_monthly_usd ?? 0);

            $proration['credit'] = 0;
            $proration['credit_usd'] = 0;
            $proration['new_price'] = $fullPriceUsd;
            $proration['new_price_usd'] = $fullPriceUsd;
            $proration['charge'] = $fullPriceUsd;
            $proration['charge_usd'] = $fullPriceUsd;
            $proration['proration_due'] = $fullPriceUsd;
            $proration['proration_due_usd'] = $fullPriceUsd;
        }

        return [
            'current_plan' => [
                'id' => $currentPlan->id,
                'name' => $currentPlan->name,
                'price_monthly_usd' => (float) ($currentPlan->price_monthly_usd ?? 0),
                'price_yearly_usd' => (float) ($currentPlan->price_yearly_usd ?? 0),
            ],
            'new_plan' => [
                'id' => $newPlan->id,
                'name' => $newPlan->name,
                'price_monthly_usd' => (float) ($newPlan->price_monthly_usd ?? 0),
                'price_yearly_usd' => (float) ($newPlan->price_yearly_usd ?? 0),
            ],
            'billing_cycle' => $targetCycle,
            'next_billing_date' => $nextBillingDate->toDateString(),
            'proration' => $proration,
        ];
    }
}
