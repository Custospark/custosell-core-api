<?php

namespace App\Services\Contracts;

use App\Models\Subscription;
use Illuminate\Database\Eloquent\Collection;

interface SubscriptionServiceInterface
{
    public function getAll(): Collection;
    public function getById(int $id): ?Subscription;
    public function getByBusiness(int $businessId): ?Subscription;
    public function create(array $data): Subscription;
    public function update(int $id, array $data): Subscription;
    public function delete(int $id): bool;
    public function getActive(): Collection;

    public function subscribe(int $businessId, int $planId, string $billingCycle = 'monthly'): Subscription;
    public function activateSubscription(Subscription $subscription, $payment = null, ?int $approvedBy = null): Subscription;
    public function renewSubscription(Subscription $subscription, $payment = null): Subscription;
    public function markPastDue(Subscription $subscription): Subscription;
    public function suspend(Subscription $subscription): Subscription;
    public function cancel(int $id, bool $immediate = false): Subscription;
    public function cancelImmediately(int $id): Subscription;
    public function reactivate(Subscription $subscription): Subscription;
    public function hasAccess(int $businessId): bool;

    public function processRenewals(): int;
    public function processCancelAtPeriodEnd(): int;
    public function processExpiredTrials(): int;
    public function processSuspensions(): int;
}
