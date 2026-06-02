<?php

namespace App\Repositories\Eloquent;

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
        return Subscription::whereIn('status', ['active', 'trialing'])
            ->with(['business', 'plan'])
            ->get();
    }
}
