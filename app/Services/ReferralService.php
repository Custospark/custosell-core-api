<?php

namespace App\Services;

use App\Enums\Billing\ReferralStatus;
use App\Models\Referral;
use App\Repositories\Contracts\ReferralCodeRepositoryInterface;
use App\Repositories\Contracts\ReferralRepositoryInterface;
use App\Repositories\Contracts\SubscriptionRepositoryInterface;
use App\Services\Contracts\ReferralServiceInterface;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class ReferralService implements ReferralServiceInterface
{
    public function __construct(
        protected ReferralRepositoryInterface $referralRepository,
        protected ReferralCodeRepositoryInterface $referralCodeRepository,
        protected SubscriptionRepositoryInterface $subscriptionRepository,
    ) {}

    public function getAll(): Collection
    {
        return $this->referralRepository->all();
    }

    public function getById(int $id): ?Referral
    {
        return $this->referralRepository->find($id);
    }

    public function getByBusiness(int $businessId): Collection
    {
        return $this->referralRepository->findByBusiness($businessId);
    }

    public function getByCode(int $codeId): Collection
    {
        return $this->referralRepository->findByCode($codeId);
    }

    public function create(array $data): Referral
    {
        return $this->referralRepository->create($data);
    }

    public function update(int $id, array $data): Referral
    {
        $referral = $this->referralRepository->find($id);
        if (!$referral) {
            throw new \RuntimeException('Referral not found');
        }
        return $this->referralRepository->update($referral, $data);
    }

    public function delete(int $id): bool
    {
        $referral = $this->referralRepository->find($id);
        if (!$referral) {
            throw new \RuntimeException('Referral not found');
        }
        return $this->referralRepository->delete($referral);
    }

    public function getPending(): Collection
    {
        return $this->referralRepository->getPending();
    }

    public function getUnpaid(): Collection
    {
        return $this->referralRepository->getUnpaid();
    }

    public function processReferral(string $code, int $subscriptionId, int $businessId): Referral
    {
        return DB::transaction(function () use ($code, $subscriptionId, $businessId) {
            $referralCode = $this->referralCodeRepository->findByCode($code);
            if (!$referralCode || !$referralCode->isValid()) {
                throw new \RuntimeException('Referral code is invalid or expired');
            }

            $existing = $this->referralRepository->findByCode($referralCode->id)
                ->first(fn ($r) => $r->referred_business_id === $businessId);
            if ($existing) {
                throw new \RuntimeException('This business has already used this referral code');
            }

            $referral = $this->referralRepository->create([
                'referral_code_id' => $referralCode->id,
                'subscription_id' => $subscriptionId,
                'referred_business_id' => $businessId,
                'status' => ReferralStatus::PENDING,
            ]);

            $referralCode->markUsed();

            return $referral;
        });
    }

    public function markActive(int $id): Referral
    {
        return DB::transaction(function () use ($id) {
            $referral = $this->referralRepository->find($id);
            if (!$referral) {
                throw new \RuntimeException('Referral not found');
            }

            return $this->referralRepository->update($referral, [
                'status' => ReferralStatus::ACTIVE,
                'converted_at' => Carbon::now(),
            ]);
        });
    }

    public function markRewarded(int $id): Referral
    {
        return DB::transaction(function () use ($id) {
            $referral = $this->referralRepository->find($id);
            if (!$referral) {
                throw new \RuntimeException('Referral not found');
            }

            return $this->referralRepository->update($referral, [
                'status' => ReferralStatus::REWARDED,
                'reward_paid' => true,
            ]);
        });
    }
}
