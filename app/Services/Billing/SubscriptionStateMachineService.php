<?php

namespace App\Services\Billing;

use App\Enums\Billing\SubscriptionStatus;
use App\Models\Subscription;
use App\Repositories\Contracts\SubscriptionRepositoryInterface;
use App\Services\Contracts\ReferralServiceInterface;
use App\Services\Contracts\SubscriptionStateMachineServiceInterface;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class SubscriptionStateMachineService implements SubscriptionStateMachineServiceInterface
{
    /**
     * Audit log for every subscription state transition.
     * Goal: trace the full subscription lifecycle (trial → active → past_due, etc.)
     * across onboarding / renewal / upgrade / cancel payments.
     *
     * @param  array<string, mixed>  $extra
     */
    private function logTransition(string $event, Subscription $subscription, array $extra = []): void
    {
        $status = $subscription->status instanceof SubscriptionStatus ? $subscription->status->value : $subscription->status;
        Log::info("[PaymentAudit] subscription {$event}", array_merge([
            'subscription_id' => $subscription->id,
            'business_id' => $subscription->business_id,
            'plan_id' => $subscription->plan_id,
            'status' => $status,
            'billing_cycle' => $subscription->billing_cycle ?? 'monthly',
            'next_billing_date' => $subscription->next_billing_date?->toDateTimeString(),
            'trial_ends_at' => $subscription->trial_ends_at?->toDateTimeString(),
            'onboarding_fee_paid' => $subscription->onboarding_fee_paid,
            'grace_period_ends_at' => $subscription->grace_period_ends_at?->toDateTimeString(),
        ], $extra));
    }
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

            // Preserve remaining trial days: if the user activates while still on trial,
            // billing starts after the trial ends, not immediately.
            $billingFrom = $subscription->status === SubscriptionStatus::TRIAL && $subscription->trial_ends_at?->isFuture()
                ? $subscription->trial_ends_at
                : $now;

            $data = [
                'status' => SubscriptionStatus::ACTIVE,
                'approved_at' => $now,
                'approved_by_user_id' => $approvedBy,
                'next_billing_date' => $this->nextBillingDate($billingFrom, $subscription->billing_cycle ?? 'monthly'),
                'grace_period_ends_at' => null,
            ];

            if ($subscription->converted_at === null) {
                $data['converted_at'] = $now;
            }

            $updated = $this->subscriptionRepository->update($subscription, $data);

            $this->referralService->activateForSubscription($subscription->id);

            $this->logTransition('activated', $updated);

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

            $updated = $this->subscriptionRepository->update($subscription, $data);
            $this->logTransition('renewed', $updated);

            return $updated;
        });
    }

    public function renewEarly(Subscription $subscription, ?int $months = null): Subscription
    {
        if ($subscription->status !== SubscriptionStatus::ACTIVE) {
            throw new \RuntimeException(
                "Cannot renew subscription early with status '{$subscription->status->value}'. Only active subscriptions can be renewed early."
            );
        }

        if ($subscription->isCancelAtPeriodEnd()) {
            throw new \RuntimeException(
                'Cannot renew a subscription that is set to cancel at the end of the billing period.'
            );
        }

        // Default to one full stored period (monthly=1, yearly=12) when no months given.
        $months = $months ?? ($subscription->billing_cycle === 'yearly' ? 12 : 1);

        if ($months < 1) {
            throw new \RuntimeException('Cannot renew early with fewer than 1 month.');
        }

        return DB::transaction(function () use ($subscription, $months) {
            // Extend from the existing schedule so the billing date does not drift.
            // Fall back to now only if a stale past date is already stored.
            $from = $subscription->next_billing_date?->isFuture() ?? false
                ? $subscription->next_billing_date
                : Carbon::now();

            $data = [
                'status' => SubscriptionStatus::ACTIVE,
                'next_billing_date' => $from->copy()->addMonths($months),
                'grace_period_ends_at' => null,
                'metadata' => array_merge($subscription->metadata ?? [], [
                    'renewed_early_at' => Carbon::now()->toDateTimeString(),
                    'topup_months' => $months,
                ]),
            ];

            $updated = $this->subscriptionRepository->update($subscription, $data);
            $this->logTransition('renewed_early', $updated, ['months' => $months]);

            return $updated;
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

            $updated = $this->subscriptionRepository->update($subscription, $data);
            $this->logTransition('marked_past_due', $updated);

            return $updated;
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

            $updated = $this->subscriptionRepository->update($subscription, $data);
            $this->logTransition('suspended', $updated);

            return $updated;
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

            $updated = $this->subscriptionRepository->update($subscription, $data);
            $this->logTransition('reactivated', $updated);

            return $updated;
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
            $trialDays = (int) ($subscription->plan->trial_days ?? config('onboarding.trial_days', 30));

            $data = [
                'onboarding_fee_paid' => true,
            ];

            if ($subscription->status === SubscriptionStatus::TRIAL && $subscription->trial_ends_at?->isFuture()) {
                $updated = $this->subscriptionRepository->update($subscription, $data);
                $this->logTransition('onboarding_paid_trial_kept', $updated, ['trial_days' => $trialDays]);
                $this->referralService->activateForSubscription($subscription->id);
                return $this->onboardingResult($subscription);
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
                if ($subscription->converted_at === null) {
                    $data['converted_at'] = $now;
                }
            }

            $updated = $this->subscriptionRepository->update($subscription, $data);
            $this->logTransition(
                'onboarded',
                $updated,
                ['trial_days' => $trialDays],
            );
            $this->referralService->activateForSubscription($subscription->id);

            return $this->onboardingResult($updated);
        });
    }

    private function onboardingResult(Subscription $subscription): Subscription
    {
        return $subscription->exists ? $subscription->fresh() : $subscription;
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

            $updated = $this->subscriptionRepository->update($subscription, $data);
            $this->logTransition($immediate ? 'cancelled_immediately' : 'cancel_requested', $updated);

            return $updated;
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
            $updated = $this->subscriptionRepository->update($subscription, [
                'status' => SubscriptionStatus::PAST_DUE,
                'grace_period_ends_at' => $now->copy()->addDays(7),
                'grace_used' => true,
            ]);
            $this->logTransition('trial_expired_to_past_due', $updated);
            return;
        }

        if ($subscription->status === SubscriptionStatus::ACTIVE && $subscription->cancel_at_period_end && $subscription->next_billing_date?->isPast()) {
            $updated = $this->subscriptionRepository->update($subscription, [
                'status' => SubscriptionStatus::CANCELLED,
                'cancelled_at' => $now,
                'ends_at' => $now,
            ]);
            $this->logTransition('cancel_period_end_applied', $updated);
            return;
        }

        if ($subscription->status === SubscriptionStatus::ACTIVE && !$subscription->cancel_at_period_end && $subscription->next_billing_date?->isPast()) {
            if ($subscription->grace_used) {
                $updated = $this->subscriptionRepository->update($subscription, [
                    'status' => SubscriptionStatus::SUSPENDED,
                    'suspended_at' => $now,
                ]);
                $this->logTransition('past_renewal_to_suspended', $updated);
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
