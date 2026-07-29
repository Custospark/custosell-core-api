<?php

namespace App\Services\Billing;

use App\Enums\Billing\SubscriptionStatus;
use App\Models\Subscription;
use App\Repositories\Contracts\SubscriptionRepositoryInterface;
use App\Services\Contracts\ReferralServiceInterface;
use App\Services\Contracts\SubscriptionStateMachineServiceInterface;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class SubscriptionStateMachineService implements SubscriptionStateMachineServiceInterface
{
    public function __construct(
        protected SubscriptionRepositoryInterface $subscriptionRepository,
        protected ReferralServiceInterface $referralService,
    ) {}

    public function activateSubscription(Subscription $subscription, $payment = null, ?int $approvedBy = null): Subscription
    {
        if (!in_array($subscription->status, [SubscriptionStatus::TRIAL, SubscriptionStatus::PAST_DUE, SubscriptionStatus::EXPIRED], true)) {
            throw new \RuntimeException(
                "Cannot activate subscription with status '{$subscription->status->value}'. Only trial, past_due or expired subscriptions can be activated."
            );
        }

        return DB::transaction(function () use ($subscription, $approvedBy) {
            $now = Carbon::now();

            $data = [
                'status' => SubscriptionStatus::ACTIVE,
                'approved_at' => $now,
                'approved_by_user_id' => $approvedBy,
                'next_billing_date' => $this->nextBillingDate($now, $subscription->billing_cycle ?? 'monthly'),
                'grace_period_ends_at' => null,
            ];

            $updated = $this->subscriptionRepository->update($subscription, $data);

            $this->referralService->activateForSubscription($subscription->id);

            return $updated;
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
                'next_billing_date' => $this->nextBillingDate($now, $subscription->billing_cycle ?? 'monthly'),
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
                'next_billing_date' => $this->nextBillingDate($now, $subscription->billing_cycle ?? 'monthly'),
                'grace_period_ends_at' => null,
            ];

            return $this->subscriptionRepository->update($subscription, $data);
        });
    }

    public function activateAfterOnboarding(Subscription $subscription): Subscription
    {
        if (!in_array($subscription->status, [SubscriptionStatus::TRIAL, SubscriptionStatus::PAST_DUE, SubscriptionStatus::EXPIRED, SubscriptionStatus::SUSPENDED], true)) {
            throw new \RuntimeException(
                "Cannot activate after onboarding with status '{$subscription->status->value}'. Only trial, past_due, expired, or suspended subscriptions can be activated after onboarding payment."
            );
        }

        return DB::transaction(function () use ($subscription) {
            $now = Carbon::now();
            $trialDays = (int) config('onboarding.trial_days', 30);

            $data = [
                'onboarding_fee_paid' => true,
            ];

            if ($subscription->status === SubscriptionStatus::TRIAL && $subscription->trial_ends_at?->isFuture()) {
                $this->subscriptionRepository->update($subscription, $data);
                $this->referralService->activateForSubscription($subscription->id);
                return $subscription->fresh();
            }

            if ($trialDays > 0 && !$subscription->trial_used) {
                $data['status'] = SubscriptionStatus::TRIAL;
                $data['trial_ends_at'] = $now->copy()->addDays($trialDays);
                $data['trial_used'] = true;
                $data['next_billing_date'] = $this->nextBillingDate($now, $subscription->billing_cycle ?? 'monthly');
                $data['approved_at'] = $now;
            } else {
                $data['status'] = SubscriptionStatus::ACTIVE;
                $data['approved_at'] = $now;
                $data['next_billing_date'] = $this->nextBillingDate($now, $subscription->billing_cycle ?? 'monthly');
            }

            $this->subscriptionRepository->update($subscription, $data);
            $this->referralService->activateForSubscription($subscription->id);

            return $subscription->fresh();
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
                $data = ['metadata' => $metadata];
            }

            return $this->subscriptionRepository->update($subscription, $data);
        });
    }

    public function cancelImmediately(int $id): Subscription
    {
        return $this->cancel($id, true);
    }

    public function processDueTransitions(Subscription $subscription): void
    {
        $now = Carbon::now();

        if ($subscription->status === SubscriptionStatus::TRIAL && $subscription->trial_ends_at?->isPast()) {
            $this->subscriptionRepository->update($subscription, [
                'status' => SubscriptionStatus::PAST_DUE,
                'grace_period_ends_at' => $now->copy()->addDays(7),
                'grace_used' => true,
            ]);
            return;
        }

        if ($subscription->status === SubscriptionStatus::ACTIVE && $subscription->cancel_at_period_end && $subscription->next_billing_date?->isPast()) {
            $this->subscriptionRepository->update($subscription, [
                'status' => SubscriptionStatus::CANCELLED,
                'cancelled_at' => $now,
                'ends_at' => $now,
            ]);
            return;
        }

        if ($subscription->status === SubscriptionStatus::ACTIVE && !$subscription->cancel_at_period_end && $subscription->next_billing_date?->isPast()) {
            if ($subscription->grace_used) {
                $this->subscriptionRepository->update($subscription, [
                    'status' => SubscriptionStatus::SUSPENDED,
                    'suspended_at' => $now,
                ]);
            } else {
                try {
                    $this->markPastDue($subscription);
                } catch (\RuntimeException) {
                }
            }
            return;
        }

        if ($subscription->status === SubscriptionStatus::PAST_DUE && $subscription->grace_period_ends_at?->isPast()) {
            try {
                $this->suspend($subscription);
            } catch (\RuntimeException) {
            }
        }
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
                    $now = Carbon::now();
                    $this->subscriptionRepository->update($subscription, [
                        'status' => SubscriptionStatus::PAST_DUE,
                        'grace_period_ends_at' => $now->copy()->addDays(7),
                        'grace_used' => true,
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

    private function nextBillingDate(Carbon $from, string $billingCycle): Carbon
    {
        return $billingCycle === 'yearly' ? $from->copy()->addYear() : $from->copy()->addMonth();
    }
}
