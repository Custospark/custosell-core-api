<?php

namespace App\Services\Contracts;

use App\Models\Subscription;

interface SubscriptionStateMachineServiceInterface
{
    public function activateSubscription(Subscription $subscription, $payment = null, ?int $approvedBy = null): Subscription;
    public function renewSubscription(Subscription $subscription, $payment = null): Subscription;
    public function markPastDue(Subscription $subscription): Subscription;
    public function suspend(Subscription $subscription): Subscription;
    public function reactivate(Subscription $subscription): Subscription;
    public function activateAfterOnboarding(Subscription $subscription): Subscription;
    public function cancel(int $id, bool $immediate = false): Subscription;
    public function cancelImmediately(int $id): Subscription;

    public function processDueTransitions(Subscription $subscription): void;
    public function processRenewals(): int;
    public function processCancelAtPeriodEnd(): int;
    public function processExpiredTrials(): int;
    public function processSuspensions(): int;
}
