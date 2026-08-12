<?php

namespace App\Services\Contracts;

use App\Models\Plan;
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
    public function processReferral(string $code, ?int $subscriptionId, int $businessId, ?array $planContext = null): Referral;
    public function resolveDiscountForCharge(Referral $referral, ?Plan $plan, string $paymentType, string $billingCycle = 'monthly'): float;
    public function markActive(int $id): Referral;
    public function markRewarded(int $id): Referral;
    public function activateForSubscription(int $subscriptionId): void;
    public function getEarningsByUser(int $userId): array;
}
