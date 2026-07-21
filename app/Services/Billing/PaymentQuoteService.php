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

    public function getQuote(Subscription $subscription, int $toPlanId): array
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
        $nextBillingDate = $subscription->next_billing_date
            ?? $subscription->ends_at
            ?? now()->addMonth();

        $proration = $this->prorationCalculator->calculateUpgradeCost(
            $currentPlan,
            $newPlan,
            $nextBillingDate,
            $billingCycle,
        );

        return [
            'current_plan' => [
                'id' => $currentPlan->id,
                'name' => $currentPlan->name,
                'price_monthly' => (float) $currentPlan->price_monthly,
                'price_yearly' => (float) $currentPlan->price_yearly,
                'price_monthly_usd' => (float) ($currentPlan->price_monthly_usd ?? 0),
                'price_yearly_usd' => (float) ($currentPlan->price_yearly_usd ?? 0),
            ],
            'new_plan' => [
                'id' => $newPlan->id,
                'name' => $newPlan->name,
                'price_monthly' => (float) $newPlan->price_monthly,
                'price_yearly' => (float) $newPlan->price_yearly,
                'price_monthly_usd' => (float) ($newPlan->price_monthly_usd ?? 0),
                'price_yearly_usd' => (float) ($newPlan->price_yearly_usd ?? 0),
            ],
            'billing_cycle' => $billingCycle,
            'next_billing_date' => $nextBillingDate->toDateString(),
            'proration' => $proration,
        ];
    }
}
