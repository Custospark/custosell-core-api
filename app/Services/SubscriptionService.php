<?php

namespace App\Services;

use App\Models\Plan;
use App\Models\Subscription;
use App\Repositories\Contracts\PlanRepositoryInterface;
use App\Repositories\Contracts\SubscriptionRepositoryInterface;
use App\Services\Contracts\ReferralServiceInterface;
use App\Services\Contracts\SubscriptionScheduledChangeServiceInterface;
use App\Services\Contracts\SubscriptionServiceInterface;
use App\Services\Contracts\SubscriptionStateMachineServiceInterface;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use App\Enums\Billing\ScheduledChangeType;
use App\Enums\Billing\SubscriptionStatus;

class SubscriptionService implements SubscriptionServiceInterface
{
    public function __construct(
        protected SubscriptionRepositoryInterface $subscriptionRepository,
        protected PlanRepositoryInterface $planRepository,
        protected ReferralServiceInterface $referralService,
        protected SubscriptionScheduledChangeServiceInterface $scheduledChangeService,
        protected SubscriptionStateMachineServiceInterface $stateMachineService,
    ) {}

    public function getAll(): Collection
    {
        return $this->subscriptionRepository->all();
    }

    public function getById(int $id): ?Subscription
    {
        return $this->subscriptionRepository->find($id);
    }

    public function getByBusiness(int $businessId): ?Subscription
    {
        $subscription = $this->subscriptionRepository->findByBusiness($businessId);
        if ($subscription) {
            $this->stateMachineService->processDueTransitions($subscription);
            $subscription = $subscription->fresh();
        }
        return $subscription;
    }

    public function create(array $data): Subscription
    {
        $planId = $data['plan_id'] ?? null;
        if ($planId) {
            $plan = $this->planRepository->find($planId);
            if ($plan) {
                $data['price_monthly_usd'] = $plan->price_monthly_usd;
                $data['price_yearly_usd'] = $plan->price_yearly_usd;
                $data['onboarding_fee_usd'] = $plan->onboarding_fee_usd;
            }
        }
        return $this->subscriptionRepository->create($data);
    }

    public function update(int $id, array $data): Subscription
    {
        $subscription = $this->subscriptionRepository->find($id);
        if (!$subscription) {
            throw new \RuntimeException('Subscription not found');
        }
        return $this->subscriptionRepository->update($subscription, $data);
    }

    public function delete(int $id): bool
    {
        $subscription = $this->subscriptionRepository->find($id);
        if (!$subscription) {
            throw new \RuntimeException('Subscription not found');
        }
        return $this->subscriptionRepository->delete($subscription);
    }

    public function getActive(): Collection
    {
        return $this->subscriptionRepository->getActive();
    }

    private function nextBillingDate(Carbon $from, string $billingCycle): Carbon
    {
        return $billingCycle === 'yearly' ? $from->copy()->addYear() : $from->copy()->addMonth();
    }

    public function subscribe(int $businessId, int $planId, string $billingCycle = 'monthly', ?string $referralCode = null, bool $skipTrial = false): Subscription
    {
        $plan = $this->planRepository->find($planId);
        if (!$plan) {
            throw new \RuntimeException('Plan not found');
        }

        $existing = $this->subscriptionRepository->findByBusiness($businessId);
        if ($existing) {
            throw new \RuntimeException('Business already has a subscription');
        }

        $now = Carbon::now();

        $data = [
            'business_id' => $businessId,
            'plan_id' => $planId,
            'price_monthly_usd' => $plan->price_monthly_usd,
            'price_yearly_usd' => $plan->price_yearly_usd,
            'onboarding_fee_usd' => $plan->onboarding_fee_usd,
            'billing_cycle' => $billingCycle,
            'status' => SubscriptionStatus::PAST_DUE,
            'starts_at' => $now,
            'trial_ends_at' => null,
            'next_billing_date' => $this->nextBillingDate($now, $billingCycle),
            'onboarding_fee_paid' => false,
            'trial_used' => false,
        ];

        if (!$skipTrial) {
            $trialDays = (int) ($plan->trial_days ?? 0);
            if ($trialDays > 0) {
                $data['status'] = SubscriptionStatus::TRIAL;
                $data['trial_ends_at'] = $now->copy()->addDays($trialDays);
            }
        }

        $subscription = $this->subscriptionRepository->create($data);

        if ($referralCode) {
            $this->referralService->processReferral($referralCode, $subscription->id, $businessId);
        }

        return $subscription;
    }

    public function applyBillingCycleChange(Subscription $subscription, string $newBillingCycle, array $metadata = []): Subscription
    {
        $plan = $this->planRepository->find($subscription->plan_id);
        if (!$plan) {
            throw new \RuntimeException('Plan not found');
        }

        return $this->subscriptionRepository->update($subscription, [
            'billing_cycle' => $newBillingCycle,
            'price_monthly_usd' => $plan->price_monthly_usd,
            'price_yearly_usd' => $plan->price_yearly_usd,
            'onboarding_fee_usd' => $plan->onboarding_fee_usd,
            'next_billing_date' => $this->nextBillingDate(now(), $newBillingCycle),
            'metadata' => $metadata,
        ]);
    }

    public function stageBillingCycleChange(Subscription $subscription, string $newBillingCycle): Subscription
    {
        $this->subscriptionRepository->update($subscription, [
            'metadata' => array_merge($subscription->metadata ?? [], [
                'pending_billing_cycle' => $newBillingCycle,
            ]),
        ]);

        return $subscription->fresh();
    }

    public function changeBillingCycle(Subscription $subscription, string $newBillingCycle, string $effective = 'immediate'): Subscription
    {
        if (!in_array($newBillingCycle, ['monthly', 'yearly'], true)) {
            throw new \RuntimeException('Billing cycle must be monthly or yearly');
        }

        if ($subscription->billing_cycle === $newBillingCycle) {
            throw new \RuntimeException('Subscription is already on this billing cycle');
        }

        if ($subscription->billing_cycle === 'yearly' && $newBillingCycle === 'monthly' && $effective === 'immediate') {
            throw new \RuntimeException('Switching from yearly to monthly billing can only take effect at the end of the current billing period');
        }

        return DB::transaction(function () use ($subscription, $newBillingCycle, $effective) {
            if ($effective === 'end_of_period') {
                $this->scheduledChangeService->schedulePlanChange(
                    $subscription->id,
                    $subscription->plan_id,
                    ScheduledChangeType::BILLING_CYCLE_CHANGE->value,
                );

                $this->subscriptionRepository->update($subscription, [
                    'metadata' => array_merge($subscription->metadata ?? [], [
                        'pending_billing_cycle' => $newBillingCycle,
                    ]),
                ]);

                return $subscription->fresh();
            }

            $oldBillingCycle = $subscription->billing_cycle;
            $plan = $this->planRepository->find($subscription->plan_id);

            if (!$plan) {
                throw new \RuntimeException('Plan not found');
            }

            $data = [
                'billing_cycle' => $newBillingCycle,
                'price_monthly_usd' => $plan->price_monthly_usd,
                'price_yearly_usd' => $plan->price_yearly_usd,
                'onboarding_fee_usd' => $plan->onboarding_fee_usd,
                'next_billing_date' => $this->nextBillingDate(now(), $newBillingCycle),
            ];

            $this->subscriptionRepository->update($subscription, $data);

            return $subscription->fresh();
        });
    }

    public function changePlan(Subscription $subscription, int $newPlanId, ?string $billingCycle = null): Subscription
    {
        $plan = $this->planRepository->find($newPlanId);
        if (!$plan) {
            throw new \RuntimeException('Plan not found');
        }

        return DB::transaction(function () use ($subscription, $plan, $billingCycle) {
            $data = [
                'plan_id' => $plan->id,
                'price_monthly_usd' => $plan->price_monthly_usd,
                'price_yearly_usd' => $plan->price_yearly_usd,
                'onboarding_fee_usd' => $plan->onboarding_fee_usd,
            ];

            if ($billingCycle) {
                $data['billing_cycle'] = $billingCycle;
            }

            return $this->subscriptionRepository->update($subscription, $data);
        });
    }

    public function hasAccess(int $businessId): bool
    {
        $subscription = $this->subscriptionRepository->findByBusiness($businessId);

        if (!$subscription) {
            return false;
        }

        $this->stateMachineService->processDueTransitions($subscription);

        return $subscription->fresh()->hasAccess();
    }

    public function activateSubscription(Subscription $subscription, $payment = null, ?int $approvedBy = null): Subscription
    {
        return $this->stateMachineService->activateSubscription($subscription, $payment, $approvedBy);
    }

    public function renewSubscription(Subscription $subscription, $payment = null): Subscription
    {
        return $this->stateMachineService->renewSubscription($subscription, $payment);
    }

    public function markPastDue(Subscription $subscription): Subscription
    {
        return $this->stateMachineService->markPastDue($subscription);
    }

    public function suspend(Subscription $subscription): Subscription
    {
        return $this->stateMachineService->suspend($subscription);
    }

    public function reactivate(Subscription $subscription): Subscription
    {
        return $this->stateMachineService->reactivate($subscription);
    }

    public function activateAfterOnboarding(Subscription $subscription): Subscription
    {
        return $this->stateMachineService->activateAfterOnboarding($subscription);
    }

    public function cancel(int $id, bool $immediate = false): Subscription
    {
        return $this->stateMachineService->cancel($id, $immediate);
    }

    public function cancelImmediately(int $id): Subscription
    {
        return $this->stateMachineService->cancelImmediately($id);
    }

    public function processRenewals(): int
    {
        return $this->stateMachineService->processRenewals();
    }

    public function processCancelAtPeriodEnd(): int
    {
        return $this->stateMachineService->processCancelAtPeriodEnd();
    }

    public function processExpiredTrials(): int
    {
        return $this->stateMachineService->processExpiredTrials();
    }

    public function processSuspensions(): int
    {
        return $this->stateMachineService->processSuspensions();
    }
}
