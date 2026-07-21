<?php

namespace App\Services\Contracts;

use App\Models\Referral;
use Illuminate\Database\Eloquent\Collection;

interface ReferralServiceInterface
{
    public function getAll(): Collection;
    public function getById(int $id): ?Referral;
    public function getByBusiness(int $businessId): Collection;
    public function getByCode(int $codeId): Collection;
    public function create(array $data): Referral;
    public function update(int $id, array $data): Referral;
    public function delete(int $id): bool;
    public function getPending(): Collection;
    public function getUnpaid(): Collection;
    public function processReferral(string $code, int $subscriptionId, int $businessId): Referral;
    public function markActive(int $id): Referral;
    public function markRewarded(int $id): Referral;
}
