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

        $billingCycle = $billingCycleOverride ?? $subscription->billing_cycle ?? 'monthly';
        $nextBillingDate = $subscription->next_billing_date
            ?? $subscription->ends_at
            ?? now()->addMonth();

        $subscriptionPrices = [
            'price_monthly_usd' => $currentPlan->price_monthly_usd,
            'price_yearly_usd' => $currentPlan->price_yearly_usd,
        ];

        $status = $subscription->status instanceof \App\Enums\Billing\SubscriptionStatus
            ? $subscription->status->value
            : $subscription->status;
        $isTrial = in_array($status, ['trial', 'trialing'], true);
        if ($isTrial) {
            $proration = [
                'proration_due' => 0,
                'days_remaining' => 0,
                'days_in_period' => 0,
                'credit' => 0,
                'charge' => 0,
                'old_price' => 0,
                'new_price' => 0,
                'old_price_usd' => 0,
                'new_price_usd' => 0,
                'proration_due_usd' => 0,
                'credit_usd' => 0,
                'charge_usd' => 0,
            ];
        } else {
            $proration = $this->prorationCalculator->calculateUpgradeCost(
                $currentPlan,
                $newPlan,
                $nextBillingDate,
                $billingCycle,
                $subscriptionPrices,
            );
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
            'billing_cycle' => $billingCycle,
            'next_billing_date' => $nextBillingDate->toDateString(),
            'proration' => $proration,
        ];
    }
}
