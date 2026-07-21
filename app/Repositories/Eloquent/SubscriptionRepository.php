<?php

namespace App\Repositories\Eloquent;

use App\Enums\Billing\SubscriptionStatus;
use App\Models\Subscription;
use App\Repositories\Contracts\SubscriptionRepositoryInterface;
use Illuminate\Database\Eloquent\Collection;

class SubscriptionRepository implements SubscriptionRepositoryInterface
{
    public function all(): Collection
    {
        return Subscription::with(['business', 'plan'])->get();
    }

    public function find(int $id): ?Subscription
    {
        return Subscription::with(['business', 'plan'])->find($id);
    }

    public function findByBusiness(int $businessId): ?Subscription
    {
        return Subscription::where('business_id', $businessId)
            ->with('plan')
            ->first();
    }

    public function create(array $data): Subscription
    {
        return Subscription::create($data);
    }

    public function update(Subscription $subscription, array $data): Subscription
    {
        $subscription->update($data);
        return $subscription->fresh();
    }

    public function delete(Subscription $subscription): bool
    {
        return $subscription->delete();
    }

    public function getActive(): Collection
    {
        return Subscription::whereIn('status', [
            SubscriptionStatus::ACTIVE->value,
            SubscriptionStatus::TRIAL->value,
        ])->with(['business', 'plan'])
            ->get();
    }

    public function getPendingGracePeriod(): Collection
    {
        return Subscription::whereIn('status', [
            SubscriptionStatus::ACTIVE->value,
            SubscriptionStatus::TRIAL->value,
        ])->where('next_billing_date', '<', now())
            ->with(['business', 'plan'])
            ->get();
    }

    public function getGraceExpired(): Collection
    {
        return Subscription::where('status', SubscriptionStatus::PAST_DUE->value)
            ->where('grace_period_ends_at', '<', now())
            ->with(['business', 'plan'])
            ->get();
    }

    public function getTrialExpired(): Collection
    {
        return Subscription::where('status', SubscriptionStatus::TRIAL->value)
            ->where('trial_ends_at', '<', now())
            ->with(['business', 'plan'])
            ->get();
    }

    public function getCancelAtPeriodEnd(): Collection
    {
        return Subscription::where('status', SubscriptionStatus::ACTIVE->value)
            ->where('metadata->cancel_at_period_end', true)
            ->where('next_billing_date', '<=', now())
            ->with(['business', 'plan'])
            ->get();
    }

    public function getRenewable(): Collection
    {
        return Subscription::where('status', SubscriptionStatus::ACTIVE->value)
            ->where(function ($q) {
                $q->whereNull('metadata->cancel_at_period_end')
                  ->orWhere('metadata->cancel_at_period_end', false);
            })
            ->where('next_billing_date', '<=', now())
            ->with(['business', 'plan'])
            ->get();
    }

    public function getPastDueExpired(): Collection
    {
        return Subscription::where('status', SubscriptionStatus::PAST_DUE->value)
            ->where('grace_period_ends_at', '<', now())
            ->with(['business', 'plan'])
            ->get();
    }
}
