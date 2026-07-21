<?php

namespace App\Services\Contracts;

use App\Models\SubscriptionScheduledChange;

interface SubscriptionScheduledChangeServiceInterface
{
    public function schedulePlanChange(int $subscriptionId, int $toPlanId, string $changeType): SubscriptionScheduledChange;
    public function scheduleCancellation(int $subscriptionId): SubscriptionScheduledChange;
    public function cancelPendingChange(int $subscriptionId): void;
    public function applyPendingChanges(): void;
    public function getPendingForSubscription(int $subscriptionId): ?SubscriptionScheduledChange;
}
