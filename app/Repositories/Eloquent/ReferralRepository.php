<?php

namespace App\Repositories\Eloquent;

use App\Models\Referral;
use App\Repositories\Contracts\ReferralRepositoryInterface;
use Illuminate\Database\Eloquent\Collection;

class ReferralRepository implements ReferralRepositoryInterface
{
    public function all(): Collection
    {
        return Referral::all();
    }

    public function find(int $id): ?Referral
    {
        return Referral::find($id);
    }

    public function findByCode(int $codeId): Collection
    {
        return Referral::where('referral_code_id', $codeId)->get();
    }

    public function findBySubscription(int $subscriptionId): ?Referral
    {
        return Referral::where('subscription_id', $subscriptionId)->first();
    }

    public function findByBusiness(int $businessId): Collection
    {
        return Referral::where('referred_business_id', $businessId)->get();
    }

    public function create(array $data): Referral
    {
        return Referral::create($data);
    }

    public function update(Referral $referral, array $data): Referral
    {
        $referral->update($data);
        return $referral->fresh();
    }

    public function delete(Referral $referral): bool
    {
        return $referral->delete();
    }

    public function getPending(): Collection
    {
        return Referral::pending()->get();
    }

    public function getUnpaid(): Collection
    {
        return Referral::unpaid()->get();
    }

    public function getByStatus(string $status): Collection
    {
        return Referral::where('status', $status)->get();
    }
}
