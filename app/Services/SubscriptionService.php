<?php

namespace App\Services;

use App\Models\Plan;
use App\Models\Subscription;
use App\Repositories\Contracts\PlanRepositoryInterface;
use App\Repositories\Contracts\SubscriptionRepositoryInterface;
use App\Services\Contracts\SubscriptionServiceInterface;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

use App\Enums\Billing\SubscriptionStatus;

class SubscriptionService implements SubscriptionServiceInterface
{
    public function __construct(
        protected SubscriptionRepositoryInterface $subscriptionRepository,
        protected PlanRepositoryInterface $planRepository,
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
        return $this->subscriptionRepository->findByBusiness($businessId);
    }

    public function create(array $data): Subscription
    {
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

    public function subscribe(int $businessId, int $planId, string $billingCycle = 'monthly'): Subscription
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
            'billing_cycle' => $billingCycle,
            'status' => SubscriptionStatus::PAST_DUE,
            'starts_at' => $now,
            'trial_ends_at' => null,
            'next_billing_date' => $now->copy()->addMonth(),
        ];

        $trialDays = (int) ($plan->trial_days ?? 0);
        if ($trialDays > 0) {
            $data['status'] = SubscriptionStatus::TRIAL;
            $data['trial_ends_at'] = $now->copy()->addDays($trialDays);
            $data['trial_used'] = true;
        }

        return $this->subscriptionRepository->create($data);
    }

    public function activateSubscription(Subscription $subscription, $payment = null, ?int $approvedBy = null): Subscription
    {
        if (!in_array($subscription->status, [SubscriptionStatus::TRIAL, SubscriptionStatus::PAST_DUE], true)) {
            throw new \RuntimeException(
                "Cannot activate subscription with status '{$subscription->status->value}'. Only trial or past_due subscriptions can be activated."
            );
        }

        return DB::transaction(function () use ($subscription, $approvedBy) {
            $now = Carbon::now();

            $data = [
                'status' => SubscriptionStatus::ACTIVE,
                'approved_at' => $now,
                'approved_by_user_id' => $approvedBy,
                'next_billing_date' => $now->copy()->addMonth(),
                'grace_period_ends_at' => null,
            ];

            return $this->subscriptionRepository->update($subscription, $data);
        });
    }

    public function renewSubscription(Subscription $subscription, $payment = null): Subscription
    {
        if ($subscription->status !== SubscriptionStatus::ACTIVE) {
            throw new \RuntimeException(
                "Cannot renew subscription with status '{$subscription->status->value}'. Only active subscriptions can be renewed."
            );
        }

        return DB::transaction(function () use ($subscription) {
            $now = Carbon::now();

            $data = [
                'status' => SubscriptionStatus::ACTIVE,
                'next_billing_date' => $now->copy()->addMonth(),
                'grace_period_ends_at' => null,
            ];

            return $this->subscriptionRepository->update($subscription, $data);
        });
    }

    public function markPastDue(Subscription $subscription): Subscription
    {
        if ($subscription->status !== SubscriptionStatus::ACTIVE) {
            throw new \RuntimeException(
                "Cannot mark subscription as past_due with status '{$subscription->status->value}'. Only active subscriptions can become past due."
            );
        }

        if ($subscription->grace_used) {
            throw new \RuntimeException('Grace period has already been used for this subscription. Cannot extend grace period.');
        }

        return DB::transaction(function () use ($subscription) {
            $now = Carbon::now();

            $data = [
                'status' => SubscriptionStatus::PAST_DUE,
                'grace_period_ends_at' => $now->copy()->addDays(7),
                'grace_used' => true,
            ];

            return $this->subscriptionRepository->update($subscription, $data);
        });
    }

    public function suspend(Subscription $subscription): Subscription
    {
        if (!in_array($subscription->status, [SubscriptionStatus::PAST_DUE, SubscriptionStatus::ACTIVE], true)) {
            throw new \RuntimeException(
                "Cannot suspend subscription with status '{$subscription->status->value}'. Only past_due or active subscriptions can be suspended."
            );
        }

        return DB::transaction(function () use ($subscription) {
            $data = [
                'status' => SubscriptionStatus::SUSPENDED,
                'suspended_at' => Carbon::now(),
            ];

            return $this->subscriptionRepository->update($subscription, $data);
        });
    }

    public function reactivate(Subscription $subscription): Subscription
    {
        if ($subscription->status !== SubscriptionStatus::SUSPENDED) {
            throw new \RuntimeException(
                "Cannot reactivate subscription with status '{$subscription->status->value}'. Only suspended subscriptions can be reactivated."
            );
        }

        return DB::transaction(function () use ($subscription) {
            $now = Carbon::now();

            $data = [
                'status' => SubscriptionStatus::ACTIVE,
                'suspended_at' => null,
                'approved_at' => $now,
                'next_billing_date' => $now->copy()->addMonth(),
                'grace_period_ends_at' => null,
            ];

            return $this->subscriptionRepository->update($subscription, $data);
        });
    }

    public function cancel(int $id, bool $immediate = false): Subscription
    {
        $subscription = $this->subscriptionRepository->find($id);
        if (!$subscription) {
            throw new \RuntimeException('Subscription not found');
        }

        if (in_array($subscription->status, [SubscriptionStatus::CANCELLED, SubscriptionStatus::EXPIRED], true)) {
            throw new \RuntimeException(
                "Cannot cancel subscription with status '{$subscription->status->value}'. Subscription is already ended."
            );
        }

        return DB::transaction(function () use ($subscription, $immediate) {
            $now = Carbon::now();

            if ($immediate || $subscription->status === SubscriptionStatus::SUSPENDED) {
                $data = [
                    'status' => SubscriptionStatus::CANCELLED,
                    'cancelled_at' => $now,
                    'ends_at' => $now,
                ];
            } else {
                $metadata = array_merge($subscription->metadata ?? [], [
                    'cancel_at_period_end' => true,
                ]);

                $data = [
                    'metadata' => $metadata,
                ];
            }

            return $this->subscriptionRepository->update($subscription, $data);
        });
    }

    public function cancelImmediately(int $id): Subscription
    {
        return $this->cancel($id, true);
    }

    public function hasAccess(int $businessId): bool
    {
        $subscription = $this->subscriptionRepository->findByBusiness($businessId);

        if (!$subscription) {
            return false;
        }

        return $subscription->hasAccess();
    }

    public function processRenewals(): int
    {
        $renewable = $this->subscriptionRepository->getRenewable();
        $count = 0;

        foreach ($renewable as $subscription) {
            try {
                $this->markPastDue($subscription);
                $count++;
            } catch (\Exception) {
            }
        }

        return $count;
    }

    public function processCancelAtPeriodEnd(): int
    {
        $toCancel = $this->subscriptionRepository->getCancelAtPeriodEnd();
        $count = 0;

        foreach ($toCancel as $subscription) {
            try {
                DB::transaction(function () use ($subscription, &$count) {
                    $now = Carbon::now();
                    $this->subscriptionRepository->update($subscription, [
                        'status' => SubscriptionStatus::CANCELLED,
                        'cancelled_at' => $now,
                        'ends_at' => $now,
                    ]);
                    $count++;
                });
            } catch (\Exception) {
            }
        }

        return $count;
    }

    public function processExpiredTrials(): int
    {
        $expired = $this->subscriptionRepository->getTrialExpired();
        $count = 0;

        foreach ($expired as $subscription) {
            try {
                DB::transaction(function () use ($subscription, &$count) {
                    $this->subscriptionRepository->update($subscription, [
                        'status' => SubscriptionStatus::EXPIRED,
                        'ends_at' => Carbon::now(),
                    ]);
                    $count++;
                });
            } catch (\Exception) {
            }
        }

        return $count;
    }

    public function processSuspensions(): int
    {
        $expired = $this->subscriptionRepository->getPastDueExpired();
        $count = 0;

        foreach ($expired as $subscription) {
            try {
                $this->suspend($subscription);
                $count++;
            } catch (\Exception) {
            }
        }

        return $count;
    }
}
