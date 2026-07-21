<?php

namespace App\Repositories\Contracts;

use App\Models\Subscription;
use Illuminate\Database\Eloquent\Collection;

interface SubscriptionRepositoryInterface
{
    public function all(): Collection;

    public function find(int $id): ?Subscription;

    public function findByBusiness(int $businessId): ?Subscription;

    public function create(array $data): Subscription;

    public function update(Subscription $subscription, array $data): Subscription;

    public function delete(Subscription $subscription): bool;

    public function getActive(): Collection;

    public function getPendingGracePeriod(): Collection;

    public function getGraceExpired(): Collection;

    public function getTrialExpired(): Collection;

    public function getCancelAtPeriodEnd(): Collection;

    public function getRenewable(): Collection;

    public function getPastDueExpired(): Collection;
}
