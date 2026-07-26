<?php

namespace App\Services;

use App\Enums\Billing\CommissionType;
use App\Enums\Billing\DiscountType;
use App\Enums\Billing\ReferralCodeOwnerType;
use App\Enums\Billing\ReferralStatus;
use App\Enums\Billing\RewardType;
use App\Models\Referral;
use App\Models\ReferralCode;
use App\Models\SalesRep;
use App\Repositories\Contracts\ReferralCodeRepositoryInterface;
use App\Repositories\Contracts\ReferralRepositoryInterface;
use App\Repositories\Contracts\SubscriptionRepositoryInterface;
use App\Services\Contracts\ReferralServiceInterface;
use App\Services\CreditService;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class ReferralService implements ReferralServiceInterface
{
    public function __construct(
        protected ReferralRepositoryInterface $referralRepository,
        protected ReferralCodeRepositoryInterface $referralCodeRepository,
        protected SubscriptionRepositoryInterface $subscriptionRepository,
        protected CreditService $creditService,
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

            // Prevent a business from using its own owner's referral code
            $subscription = $this->subscriptionRepository->find($subscriptionId);
            if ($subscription && $referralCode->owner_user_id) {
                $business = $subscription->business;
                if ($business && $business->owner_id === $referralCode->owner_user_id) {
                    throw new \RuntimeException('You cannot use your own referral code');
                }
            }

            // One-time use per business — no stacking across codes or resubscribes
            if ($this->referralRepository->findByBusiness($businessId)->isNotEmpty()) {
                throw new \RuntimeException('This business has already used a referral code');
            }

            // Same-code duplicate guard
            $existing = $this->referralRepository->findByCode($referralCode->id)
                ->first(fn ($r) => $r->referred_business_id === $businessId);
            if ($existing) {
                throw new \RuntimeException('This business has already used this referral code');
            }

            // Calculate discount and reward based on plan's USD price
            $monthlyPriceUsd = (float) ($subscription?->plan?->price_monthly_usd ?? 0);

            $discountApplied = match ($referralCode->discount_type) {
                DiscountType::PERCENTAGE => round($monthlyPriceUsd * ((float) ($referralCode->discount_value ?? 0) / 100), 2),
                DiscountType::FLAT_AMOUNT => (float) ($referralCode->discount_value ?? 0),
                DiscountType::FREE_MONTH => $monthlyPriceUsd,
            };

            $referral = $this->referralRepository->create([
                'referral_code_id' => $referralCode->id,
                'subscription_id' => $subscriptionId,
                'referred_business_id' => $businessId,
                'status' => ReferralStatus::PENDING,
                'discount_applied' => $discountApplied,
                'reward_amount' => 0,
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

            $updateData = [
                'status' => ReferralStatus::ACTIVE,
                'converted_at' => Carbon::now(),
            ];

            $referralCode = $referral->referralCode;
            if (!$referralCode) {
                throw new \RuntimeException('Referral code not found');
            }

            $subscription = $referral->subscription;
            $monthlyPriceUsd = (float) ($subscription?->plan?->price_monthly_usd ?? 0);

            // Calculate reward for the referrer (business-owned codes)
            $rewardAmount = match ($referralCode->reward_type) {
                RewardType::PERCENTAGE => round($monthlyPriceUsd * ((float) ($referralCode->reward_value ?? 0) / 100), 2),
                RewardType::FLAT_AMOUNT => (float) ($referralCode->reward_value ?? 0),
                RewardType::FREE_MONTH => $monthlyPriceUsd,
                default => 0,
            };
            $updateData['reward_amount'] = $rewardAmount;

            // Create billing credit from the reward
            if ($rewardAmount > 0) {
                $this->creditService->createFromReferral($referral, $rewardAmount);
            }

            // Calculate commission for sales reps
            if ($referralCode->owner_type === ReferralCodeOwnerType::SALES_REP) {
                $salesRep = SalesRep::where('referral_code_id', $referralCode->id)->first();
                if ($salesRep && $salesRep->is_active) {
                    $commissionEarned = match ($salesRep->commission_type) {
                        CommissionType::PERCENTAGE => round($monthlyPriceUsd * ((float) ($salesRep->commission_rate ?? 0) / 100), 2),
                        CommissionType::FLAT => (float) ($salesRep->commission_rate ?? 0),
                    };

                    $updateData['commission_earned'] = $commissionEarned;
                }
            }

            return $this->referralRepository->update($referral, $updateData);
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

    public function activateForSubscription(int $subscriptionId): void
    {
        $referral = $this->referralRepository->findBySubscription($subscriptionId);
        if ($referral && $referral->status === ReferralStatus::PENDING) {
            $this->markActive($referral->id);
        }
    }

    public function getEarningsByUser(int $userId): array
    {
        // Check if this user is a sales rep first
        $salesRep = SalesRep::where('user_id', $userId)->with('referralCode')->first();
        $userCode = $salesRep?->referralCode
            ?? ReferralCode::where('owner_user_id', $userId)->first();

        if (!$userCode) {
            return [
                'referral_code' => null,
                'is_sales_rep' => false,
                'total_earned' => 0,
                'pending_rewards' => 0,
                'rewarded_amount' => 0,
                'commission_earned' => 0,
                'commission_pending' => 0,
                'commission_paid' => 0,
                'total_referrals' => 0,
                'active_referrals' => 0,
                'referrals' => [],
            ];
        }

        $referrals = Referral::where('referral_code_id', $userCode->id)
            ->with('referredBusiness')
            ->orderBy('created_at', 'desc')
            ->get();

        $totalCommission = (float) $referrals->sum('commission_earned');
        $paidCommission = $salesRep ? (float) $salesRep->payouts()->sum('amount') : 0;

        return [
            'referral_code' => $userCode->code,
            'is_sales_rep' => $salesRep !== null,
            'commission_rate' => $salesRep?->commission_rate,
            'commission_type' => $salesRep?->commission_type,
            'total_earned' => (float) $referrals->sum('reward_amount'),
            'pending_rewards' => (float) $referrals->where('status', ReferralStatus::ACTIVE)->where('reward_paid', false)->sum('reward_amount'),
            'rewarded_amount' => (float) $referrals->where('status', ReferralStatus::REWARDED)->sum('reward_amount'),
            'commission_earned' => $totalCommission,
            'commission_pending' => round(max(0, $totalCommission - $paidCommission), 2),
            'commission_paid' => $paidCommission,
            'total_referrals' => $referrals->count(),
            'active_referrals' => $referrals->where('status', ReferralStatus::ACTIVE)->count(),
            'referrals' => $referrals->toArray(),
        ];
    }
}
