<?php

namespace App\Repositories\Contracts;

use App\Models\Referral;
use Illuminate\Database\Eloquent\Collection;

interface ReferralRepositoryInterface
{
    public function all(): Collection;

    public function find(int $id): ?Referral;

    public function findByCode(int $codeId): Collection;

    public function findBySubscription(int $subscriptionId): ?Referral;

    public function findByBusiness(int $businessId): Collection;

    public function create(array $data): Referral;

    public function update(Referral $referral, array $data): Referral;

    public function delete(Referral $referral): bool;

    public function getPending(): Collection;

    public function getUnpaid(): Collection;

    public function getByStatus(string $status): Collection;
}
