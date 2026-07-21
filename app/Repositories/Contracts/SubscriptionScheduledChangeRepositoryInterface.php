<?php

namespace App\Repositories\Contracts;

use App\Models\SubscriptionScheduledChange;
use Illuminate\Database\Eloquent\Collection;

interface SubscriptionScheduledChangeRepositoryInterface
{
    public function find(int $id): ?SubscriptionScheduledChange;
    public function findPendingForSubscription(int $subscriptionId): ?SubscriptionScheduledChange;
    public function findDuePending(): Collection;
    public function create(array $data): SubscriptionScheduledChange;
    public function update(SubscriptionScheduledChange $change, array $data): SubscriptionScheduledChange;
    public function delete(SubscriptionScheduledChange $change): bool;
    public function cancelPendingForSubscription(int $subscriptionId): void;
}
