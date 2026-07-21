<?php

namespace App\Repositories\Eloquent;

use App\Models\SubscriptionScheduledChange;
use App\Repositories\Contracts\SubscriptionScheduledChangeRepositoryInterface;
use Illuminate\Database\Eloquent\Collection;

class SubscriptionScheduledChangeRepository implements SubscriptionScheduledChangeRepositoryInterface
{
    public function find(int $id): ?SubscriptionScheduledChange
    {
        return SubscriptionScheduledChange::with(['fromPlan', 'toPlan', 'subscription.plan'])->find($id);
    }

    public function findPendingForSubscription(int $subscriptionId): ?SubscriptionScheduledChange
    {
        return SubscriptionScheduledChange::where('subscription_id', $subscriptionId)
            ->where('status', 'pending')
            ->first();
    }

    public function findDuePending(): Collection
    {
        return SubscriptionScheduledChange::where('status', 'pending')
            ->where('effective_at', '<=', now())
            ->with(['subscription.plan', 'fromPlan', 'toPlan'])
            ->get();
    }

    public function create(array $data): SubscriptionScheduledChange
    {
        return SubscriptionScheduledChange::create($data);
    }

    public function update(SubscriptionScheduledChange $change, array $data): SubscriptionScheduledChange
    {
        $change->update($data);
        return $change->fresh();
    }

    public function delete(SubscriptionScheduledChange $change): bool
    {
        return $change->delete();
    }

    public function cancelPendingForSubscription(int $subscriptionId): void
    {
        SubscriptionScheduledChange::where('subscription_id', $subscriptionId)
            ->where('status', 'pending')
            ->update(['status' => 'cancelled']);
    }
}
