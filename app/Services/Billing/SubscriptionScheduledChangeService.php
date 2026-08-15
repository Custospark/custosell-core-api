<?php

namespace App\Services\Billing;

use App\Enums\Billing\ScheduledChangeType;
use App\Enums\Billing\SubscriptionStatus;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\SubscriptionScheduledChange;
use App\Repositories\Contracts\PlanRepositoryInterface;
use App\Repositories\Contracts\SubscriptionRepositoryInterface;
use App\Repositories\Contracts\SubscriptionScheduledChangeRepositoryInterface;
use App\Services\Contracts\SubscriptionScheduledChangeServiceInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class SubscriptionScheduledChangeService implements SubscriptionScheduledChangeServiceInterface
{
    public function __construct(
        protected SubscriptionRepositoryInterface $subscriptionRepo,
        protected PlanRepositoryInterface $planRepo,
        protected SubscriptionScheduledChangeRepositoryInterface $scheduledChangeRepo,
    ) {}

    public function schedulePlanChange(int $subscriptionId, int $toPlanId, string $changeType): SubscriptionScheduledChange
    {
        $subscription = $this->subscriptionRepo->find($subscriptionId);
        if (!$subscription) {
            throw new \RuntimeException('Subscription not found');
        }

        $targetPlan = $this->planRepo->find($toPlanId);
        if (!$targetPlan) {
            throw new \RuntimeException('Target plan not found');
        }

        if ($subscription->plan_id === $targetPlan->id) {
            throw new \RuntimeException('Business is already on this plan');
        }

        // A downgrade is only schedulable while the subscription is active.
        // (It is applied later regardless of the subscription's status - the
        // guard lives in applyPendingChanges().)
        $status = $subscription->status instanceof SubscriptionStatus
            ? $subscription->status->value
            : $subscription->status;

        if ($changeType === 'downgrade' && $status !== 'active') {
            throw new \RuntimeException('A downgrade can only be scheduled while the subscription is active.');
        }

        $this->scheduledChangeRepo->cancelPendingForSubscription($subscriptionId);

        $effectiveAt = $subscription->next_billing_date
            ?? $subscription->ends_at
            ?? now()->addMonth();

        $change = $this->scheduledChangeRepo->create([
            'subscription_id' => $subscriptionId,
            'business_id' => $subscription->business_id,
            'change_type' => $changeType,
            'from_plan_id' => $subscription->plan_id,
            'to_plan_id' => $toPlanId,
            'effective_at' => $effectiveAt,
            'status' => 'pending',
            'metadata' => [
                'target_plan_name' => $targetPlan->name,
            ],
        ]);

        Log::info('[SubscriptionScheduledChange] Plan change scheduled', [
            'subscription_id' => $subscriptionId,
            'from_plan_id' => $subscription->plan_id,
            'to_plan_id' => $toPlanId,
            'effective_at' => $effectiveAt->toDateTimeString(),
        ]);

        return $change;
    }

    public function scheduleCancellation(int $subscriptionId): SubscriptionScheduledChange
    {
        $subscription = $this->subscriptionRepo->find($subscriptionId);
        if (!$subscription) {
            throw new \RuntimeException('Subscription not found');
        }

        $this->scheduledChangeRepo->cancelPendingForSubscription($subscriptionId);

        $effectiveAt = $subscription->next_billing_date
            ?? $subscription->ends_at
            ?? now()->addMonth();

        $change = $this->scheduledChangeRepo->create([
            'subscription_id' => $subscriptionId,
            'business_id' => $subscription->business_id,
            'change_type' => 'cancel',
            'from_plan_id' => $subscription->plan_id,
            'to_plan_id' => null,
            'effective_at' => $effectiveAt,
            'status' => 'pending',
            'metadata' => [
                'cancel_at_period_end' => true,
            ],
        ]);

        Log::info('[SubscriptionScheduledChange] Cancellation scheduled', [
            'subscription_id' => $subscriptionId,
            'effective_at' => $effectiveAt->toDateTimeString(),
        ]);

        return $change;
    }

    public function cancelPendingChange(int $subscriptionId): void
    {
        $subscription = $this->subscriptionRepo->find($subscriptionId);
        if (!$subscription) {
            throw new \RuntimeException('Subscription not found');
        }

        $this->scheduledChangeRepo->cancelPendingForSubscription($subscriptionId);

        $metadata = $subscription->metadata ?? [];
        unset($metadata['cancel_at_period_end']);
        $this->subscriptionRepo->update($subscription, ['metadata' => $metadata]);

        Log::info('[SubscriptionScheduledChange] Pending change cancelled', [
            'subscription_id' => $subscriptionId,
        ]);
    }

    public function applyPendingChanges(): void
    {
        $dueChanges = $this->scheduledChangeRepo->findDuePending();

        foreach ($dueChanges as $change) {
            try {
                DB::transaction(function () use ($change) {
                    $subscription = $change->subscription;

                    if (!$subscription) {
                        Log::warning('[SubscriptionScheduledChange] Subscription not found for change', [
                            'change_id' => $change->id,
                        ]);
                        $this->scheduledChangeRepo->update($change, ['status' => 'cancelled']);
                        return;
                    }

                    $changeType = $change->change_type instanceof \App\Enums\Billing\ScheduledChangeType
                        ? $change->change_type->value
                        : $change->change_type;

                    $status = $subscription->status instanceof \App\Enums\Billing\SubscriptionStatus
                        ? $subscription->status->value
                        : $subscription->status;

                    // A downgrade needs no payment and simply carries the existing
                    // subscription/payment state forward, so it is applied regardless
                    // of the subscription's current status. Cancellations are applied
                    // in any status too. Only payment-gated / state-sensitive changes
                    // (upgrade, generic plan_change, billing_cycle_change) require an
                    // active subscription to land.
                    $requiresActive = in_array($changeType, ['upgrade', 'plan_change', 'billing_cycle_change'], true);

                    if ($requiresActive && $status !== 'active') {
                        Log::info('[SubscriptionScheduledChange] Skipped - subscription not active', [
                            'change_id' => $change->id,
                            'subscription_id' => $subscription->id,
                            'status' => $status,
                            'type' => $changeType,
                        ]);
                        return;
                    }

                    if ($changeType === 'cancel') {
                        $this->subscriptionRepo->update($subscription, [
                            'status' => 'cancelled',
                            'cancelled_at' => now(),
                            'ends_at' => now(),
                            'metadata' => array_merge($subscription->metadata ?? [], [
                                'cancel_at_period_end' => false,
                            ]),
                        ]);
                    } elseif ($changeType === 'billing_cycle_change') {
                        $pendingCycle = $subscription->metadata['pending_billing_cycle'] ?? null;
                        if ($pendingCycle && in_array($pendingCycle, ['monthly', 'yearly'], true)) {
                            $metadata = $subscription->metadata ?? [];
                            unset($metadata['pending_billing_cycle']);
                            $this->subscriptionRepo->update($subscription, [
                                'billing_cycle' => $pendingCycle,
                                'metadata' => $metadata,
                            ]);
                        }
                    } else {
                        $this->subscriptionRepo->update($subscription, [
                            'plan_id' => $change->to_plan_id,
                            'price_monthly_usd' => $change->toPlan?->price_monthly_usd ?? $subscription->price_monthly_usd,
                            'price_yearly_usd' => $change->toPlan?->price_yearly_usd ?? $subscription->price_yearly_usd,
                            'onboarding_fee_usd' => $change->toPlan?->onboarding_fee_usd ?? $subscription->onboarding_fee_usd,
                        ]);
                    }

                    $this->scheduledChangeRepo->update($change, ['status' => 'applied']);

                    Log::info('[SubscriptionScheduledChange] Change applied', [
                        'change_id' => $change->id,
                        'subscription_id' => $subscription->id,
                        'type' => $changeType,
                    ]);
                });
            } catch (\Throwable $e) {
                Log::error('[SubscriptionScheduledChange] Failed to apply change', [
                    'change_id' => $change->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    public function getPendingForSubscription(int $subscriptionId): ?SubscriptionScheduledChange
    {
        return $this->scheduledChangeRepo->findPendingForSubscription($subscriptionId);
    }
}
