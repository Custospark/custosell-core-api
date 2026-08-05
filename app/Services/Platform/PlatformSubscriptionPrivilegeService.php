<?php

namespace App\Services\Platform;

use App\Models\Business;
use App\Models\Subscription;
use App\Models\User;
use App\Services\Contracts\SubscriptionServiceInterface;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

class PlatformSubscriptionPrivilegeService
{
    /**
     * The date field that applies to a given subscription status.
     * When a platform admin sets a status, only the matching date column is
     * meaningful (trial -> trial_ends_at, active -> next_billing_date, ...).
     */
    private const DATE_FIELD_BY_STATUS = [
        'trial' => 'trial_ends_at',
        'active' => 'next_billing_date',
        'past_due' => 'grace_period_ends_at',
        'suspended' => 'suspended_at',
        'cancelled' => 'ends_at',
        'expired' => 'ends_at',
    ];

    public function __construct(
        protected SubscriptionServiceInterface $subscriptionService,
        protected PlatformAuditService $audit,
    ) {}

    /**
     * Apply subscription privilege changes, returning the before/after diff
     * for the fields that actually changed.
     *
     * @param  array{plan_id?: int, billing_cycle?: string, subscription_status?: string, onboarding_fee_paid?: bool, next_billing_date?: string, trial_ends_at?: string, grace_period_ends_at?: string, suspended_at?: string, ends_at?: string}  $changes
     * @return array<string, array{from: mixed, to: mixed}>|null  null when no subscription was touched
     */
    public function apply(User $actor, User $target, array $changes): ?array
    {
        $subscription = $this->resolveSubscription($target, $changes);

        if (! $subscription) {
            return null;
        }

        $before = $this->snapshot($subscription);

        $updates = $this->buildUpdates($subscription, $changes);

        if ($updates !== []) {
            $subscription->update($updates);
            $subscription->refresh();
        }

        $diff = $this->diff($before, $this->snapshot($subscription));

        $this->audit($actor, $subscription, $updates, $diff);

        return $diff;
    }

    /** @return bool  if at least one date-bearing status change is being made */
    public function hasSubscriptionChange(array $changes): bool
    {
        foreach (['plan_id', 'billing_cycle', 'subscription_status', 'onboarding_fee_paid',
            'next_billing_date', 'trial_ends_at', 'grace_period_ends_at', 'suspended_at', 'ends_at', ] as $key) {
            if (isset($changes[$key])) {
                return true;
            }
        }

        return false;
    }

    private function resolveSubscription(User $target, array $changes): ?Subscription
    {
        $business = $target->business ?? $target->ownedBusiness()->first();

        if (! $business) {
            throw ValidationException::withMessages(['business' => 'This account has no linked business.']);
        }

        $subscription = $business->subscription ?? $business->subscription()->first();

        if ($subscription === null && isset($changes['plan_id'])) {
            return $this->buildSubscription($business, $changes);
        }

        if ($subscription === null) {
            throw ValidationException::withMessages(['subscription' => 'No subscription exists. Select a plan to create one.']);
        }

        return $subscription;
    }

    private function buildSubscription(Business $business, array $changes): Subscription
    {
        $subscription = $this->subscriptionService->subscribe(
            $business->id,
            (int) ($changes['plan_id'] ?? null),
            $changes['billing_cycle'] ?? 'monthly',
        );

        return $this->subscriptionService->activateAfterOnboarding($subscription);
    }

    private function buildUpdates(Subscription $subscription, array $changes): array
    {
        $updates = [];

        if (isset($changes['plan_id']) && (int) $changes['plan_id'] !== $subscription->plan_id) {
            $plan = \App\Models\Plan::find((int) $changes['plan_id']);
            if (! $plan) {
                throw ValidationException::withMessages(['plan_id' => 'Selected plan not found.']);
            }

            $updates['plan_id'] = $plan->id;
            $updates['price_monthly_usd'] = $plan->price_monthly_usd;
            $updates['price_yearly_usd'] = $plan->price_yearly_usd;
            $updates['onboarding_fee_usd'] = $plan->onboarding_fee_usd;
        }

        if (isset($changes['billing_cycle'])) {
            $updates['billing_cycle'] = $changes['billing_cycle'];
            $updates['next_billing_date'] = $changes['billing_cycle'] === 'yearly'
                ? now()->addYear()
                : now()->addMonth();
        }

        if (isset($changes['subscription_status'])) {
            $updates['status'] = $changes['subscription_status'];
        }

        if (isset($changes['onboarding_fee_paid'])) {
            $updates['onboarding_fee_paid'] = (bool) $changes['onboarding_fee_paid'];
        }

        $this->applyStatusDate($updates, $subscription, $changes);

        return $updates;
    }

    private function applyStatusDate(array &$updates, Subscription $subscription, array $changes): void
    {
        $status = $updates['status'] ?? ($subscription->status?->value ?? $subscription->status);
        $field = self::DATE_FIELD_BY_STATUS[$status] ?? null;

        if ($field && isset($changes[$field]) && $changes[$field] !== '') {
            $updates[$field] = Carbon::parse($changes[$field]);
        }
    }

    /** @return array<string, mixed> */
    private function snapshot(Subscription $subscription): array
    {
        return [
            'plan_id' => $subscription->plan_id,
            'billing_cycle' => $subscription->billing_cycle,
            'status' => $subscription->status?->value ?? $subscription->status,
            'onboarding_fee_paid' => (bool) ($subscription->onboarding_fee_paid ?? false),
            'next_billing_date' => $subscription->next_billing_date?->toDateString(),
            'trial_ends_at' => $subscription->trial_ends_at?->toDateString(),
            'grace_period_ends_at' => $subscription->grace_period_ends_at?->toDateString(),
            'suspended_at' => $subscription->suspended_at?->toDateString(),
            'ends_at' => $subscription->ends_at?->toDateString(),
            'cancelled_at' => $subscription->cancelled_at?->toDateString(),
        ];
    }

    private function diff(array $before, array $after): array
    {
        $diff = [];

        foreach ($after as $field => $value) {
            if (($before[$field] ?? null) !== $value) {
                $diff[$field] = ['from' => $before[$field] ?? null, 'to' => $value];
            }
        }

        return $diff;
    }

    private function audit(User $actor, Subscription $subscription, array $updates, array $diff): void
    {
        $this->audit->log(
            $actor,
            'user.privileges.subscription',
            'subscription',
            $subscription->id,
            null,
            [
                'changes' => array_keys($updates),
                'diff' => $diff,
            ],
        );
    }
}