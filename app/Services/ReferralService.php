<?php

namespace App\Services;

use App\Enums\Billing\CommissionType;
use App\Enums\Billing\DiscountType;
use App\Enums\Billing\PaymentStatus;
use App\Enums\Billing\ReferralCodeOwnerType;
use App\Enums\Billing\ReferralStatus;
use App\Enums\Billing\RewardType;
use App\Models\BillingCredit;
use App\Models\BillingPayment;
use App\Models\Business;
use App\Models\Plan;
use App\Models\Referral;
use App\Models\ReferralCode;
use App\Models\SalesRep;
use App\Models\User;
use App\Repositories\Contracts\ReferralCodeRepositoryInterface;
use App\Repositories\Contracts\ReferralRepositoryInterface;
use App\Repositories\Contracts\SubscriptionRepositoryInterface;
use App\Services\Contracts\ReferralServiceInterface;
use App\Services\CreditService;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

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

    public function processReferral(string $code, ?int $subscriptionId, int $businessId, ?array $planContext = null): Referral
    {
        return DB::transaction(function () use ($code, $subscriptionId, $businessId, $planContext) {
            $referralCode = $this->referralCodeRepository->findByCode($code);
            if (!$referralCode || !$referralCode->isValid()) {
                throw new \RuntimeException('Referral code is invalid or expired');
            }

            // Prevent a business from using its own owner's referral code.
            // With a subscription we use its business; pre-subscription we
            // resolve the business directly from the referral business id.
            $subscription = $subscriptionId ? $this->subscriptionRepository->find($subscriptionId) : null;
            if ($referralCode->owner_user_id) {
                $business = $subscription?->business ?? Business::find($businessId);
                if ($business && $business->owner_id === $referralCode->owner_user_id) {
                    throw new \RuntimeException('You cannot use your own referral code');
                }
            }

            // Only a CLAIMED (paid) referral permanently locks the account. A
            // PENDING referral is just a preview of a code's worth — trying a
            // code must never earmark the account or consume the code (usage is
            // counted in markActive, once a payment is actually claimed).
            $businessReferrals = $this->referralRepository->findByBusiness($businessId);
            $claimed = $businessReferrals->first(
                fn ($r) => in_array($r->status, [ReferralStatus::ACTIVE, ReferralStatus::REWARDED], true)
            );
            if ($claimed) {
                throw new \RuntimeException('This account has already used a referral code');
            }

            // Calculate discount based on the amount being paid at the time of application
            $plan = $subscription?->plan;
            if (!$plan && !empty($planContext['plan_id'])) {
                $plan = Plan::find((int) $planContext['plan_id']);
            }
            $monthlyPriceUsd = (float) ($plan->price_monthly_usd ?? 0);
            $onboardingFeeUsd = (float) ($plan->onboarding_fee_usd ?? 0);
            $isOnboarding = $subscription && !$subscription->onboarding_fee_paid;
            $discountBase = $isOnboarding && $onboardingFeeUsd > 0 ? $onboardingFeeUsd : $monthlyPriceUsd;

            $discountApplied = match ($referralCode->discount_type) {
                DiscountType::PERCENTAGE => round($discountBase * ((float) ($referralCode->discount_value ?? 0) / 100), 2),
                DiscountType::FLAT_AMOUNT => (float) ($referralCode->discount_value ?? 0),
                DiscountType::FREE_MONTH => $discountBase,
            };

            // Latest code wins while unpaid: a PENDING referral is only a
            // preview, so applying a new code re-points it to the newer code
            // (recomputed against the business/payment) instead of stacking.
            $pending = $businessReferrals->first(fn ($r) => $r->status === ReferralStatus::PENDING);
            if ($pending) {
                return $this->referralRepository->update($pending, [
                    'referral_code_id' => $referralCode->id,
                    'subscription_id' => $subscriptionId,
                    'referred_business_id' => $businessId,
                    'discount_applied' => $discountApplied,
                ]);
            }

            $referral = $this->referralRepository->create([
                'referral_code_id' => $referralCode->id,
                'subscription_id' => $subscriptionId,
                'referred_business_id' => $businessId,
                'status' => ReferralStatus::PENDING,
                'discount_applied' => $discountApplied,
                'reward_amount' => 0,
            ]);

            // No BillingCredit created here — the discount is applied directly
            // to the payment amount in GatewayService. After payment confirms,
            // markActive() creates any remaining months as a credit.
            // NOTE: usage is NOT counted here. A code is only ever counted as
            // "used" in markActive(), once a payment has been claimed with it —
            // merely previewing/applying a code must not consume it.

            return $referral;
        });
    }

    public function resolveDiscountForCharge(
        Referral $referral,
        ?Plan $plan,
        string $paymentType,
        string $billingCycle = 'monthly'
    ): float {
        $referralCode = $referral->referralCode;
        if (!$referralCode) {
            return 0;
        }

        // Base the discount on the same fees the charge uses (the resolved plan),
        // so onboarding discounts track the plan actually being paid for.
        $onboardingFeeUsd = (float) ($plan?->onboarding_fee_usd ?? 0);
        $monthlyUsd = (float) ($plan?->price_monthly_usd ?? 0);
        $yearlyUsd = (float) ($plan?->price_yearly_usd ?? 0) ?: $monthlyUsd * 12;

        $isOnboardingBase = $paymentType === 'onboarding' && $onboardingFeeUsd > 0;
        $base = $isOnboardingBase
            ? $onboardingFeeUsd
            : ($billingCycle === 'yearly' ? $yearlyUsd : $monthlyUsd);

        if ($base <= 0) {
            return 0;
        }

        $discount = $this->discountAgainstBase($referralCode, $base);

        if ((float) $referral->discount_applied !== $discount) {
            $this->referralRepository->update($referral, ['discount_applied' => $discount]);
        }

        return $discount;
    }

    private function discountAgainstBase(ReferralCode $referralCode, float $base): float
    {
        if ($base <= 0) {
            return 0;
        }

        $discount = match ($referralCode->discount_type) {
            DiscountType::PERCENTAGE => round($base * ((float) ($referralCode->discount_value ?? 0) / 100), 2),
            DiscountType::FLAT_AMOUNT => (float) ($referralCode->discount_value ?? 0),
            DiscountType::FREE_MONTH => $base,
        };

        return min($discount, $base);
    }

    public function markActive(int $id): Referral
    {
        return DB::transaction(function () use ($id) {
            $referral = $this->referralRepository->find($id);
            if (!$referral) {
                throw new \RuntimeException('Referral not found');
            }

            // A referral may only be activated once — a code counts as "used"
            // only when a payment has actually been claimed with it.
            if ($referral->status === ReferralStatus::ACTIVE) {
                return $referral;
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
            $plan = $subscription?->plan;
            $monthlyPriceUsd = (float) ($plan->price_monthly_usd ?? 0);
            $onboardingFeeUsd = (float) ($plan->onboarding_fee_usd ?? 0);

            // Reward/commission is a % of what the referee ACTUALLY paid. The
            // authoritative figure is the confirmed payment's original amount
            // (post-referral-discount USD), which avoids ever rewarding on a
            // plan-price snapshot captured at the wrong time.
            $paidBase = 0.0;
            $paidPayment = $subscription?->payments()
                ->where('status', PaymentStatus::COMPLETED)
                ->orderByDesc('id')
                ->first();
            if ($paidPayment) {
                $paidBase = (float) ($paidPayment->metadata['original_amount'] ?? 0);
            }
            if ($paidBase <= 0) {
                // Fallback: plan-based estimate (base minus referral discount).
                $rewardBase = $monthlyPriceUsd;
                if (!$subscription->onboarding_fee_paid && $onboardingFeeUsd > 0) {
                    $rewardBase = $onboardingFeeUsd;
                }
                $paidBase = max(0, $rewardBase - (float) ($referral->discount_applied ?? 0));
            }

            // Safe-zone guard (Company > Referrer): no matter how a reward or
            // commission is configured, the referrer can never take >= 50% of
            // what the referee actually paid. Hard clamp at apply time kills
            // legacy/live codes that slipped past the request guard (or predate
            // it) — e.g. the FREE_MONTH $135-on-$180 Enterprise leak.
            $maxReferrerShare = max(0, round($paidBase * 0.5, 2) - 0.01);

            if ($referralCode->owner_type === ReferralCodeOwnerType::SALES_REP) {
                $salesRep = SalesRep::where('referral_code_id', $referralCode->id)->first();
                if ($salesRep && $salesRep->is_active) {
                    $commissionEarned = match ($salesRep->commission_type) {
                        CommissionType::PERCENTAGE => round($paidBase * ((float) ($salesRep->commission_rate ?? 0) / 100), 2),
                        CommissionType::FLAT => (float) ($salesRep->commission_rate ?? 0),
                    };
                    $updateData['commission_earned'] = min($commissionEarned, $maxReferrerShare);
                }
            } elseif ($referralCode->owner_type === ReferralCodeOwnerType::BUSINESS) {
                // Staff who work in a business they don't own keep their earned
                // reward as a personal commission credit (see CreditService::
                // createFromReferral) — their referrals never fund the business
                // promo-credit pool. The referee still gets the discount.
                $cycle = (string) ($subscription?->billing_cycle ?? 'monthly');
                $yearlyUsd = (float) ($plan?->price_yearly_usd ?? 0) ?: $monthlyPriceUsd * 12;
                $recurringMonthly = $cycle === 'yearly' ? round($yearlyUsd / 12, 2) : $monthlyPriceUsd;

                $rewardAmount = match ($referralCode->reward_type) {
                    RewardType::PERCENTAGE => round($paidBase * ((float) ($referralCode->reward_value ?? 0) / 100), 2),
                    RewardType::FLAT_AMOUNT => (float) ($referralCode->reward_value ?? 0),
                    RewardType::FREE_MONTH => round(min($recurringMonthly, $paidBase), 2),
                    default => 0,
                };
                $updateData['reward_amount'] = min($rewardAmount, $maxReferrerShare);

                if ($updateData['reward_amount'] > 0) {
                    $this->creditService->createFromReferral($referral, $updateData['reward_amount']);
                }
            }
            // CAMPAIGN codes (company-owned) intentionally earn no reward:
            // the company shouldn't credit itself credits/free months for its own
            // promotions. Referee discount still applies via markActive's credit below.

            // Audit log — distribution computed for referrer (reward/commission) and referee (discount base)
            // that passed through to BillingCredit / payout. Goal: verify commission distribution accuracy.
            Log::info('[PaymentAudit] referral reward/commission computed', [
                'referral_id' => $referral->id,
                'referral_code' => $referralCode->code,
                'referred_business_id' => $referral->referred_business_id,
                'owner_type' => $referralCode->owner_type->value ?? $referralCode->owner_type,
                'owner_user_id' => $referralCode->owner_user_id,
                'owner_business_id' => $referralCode->owner_business_id,
                'monthly_price_usd' => $monthlyPriceUsd,
                'onboarding_fee_usd' => $onboardingFeeUsd,
                'reward_base_usd' => $paidBase,
                'discount_applied_usd' => (float) ($referral->discount_applied ?? 0),
                'paid_base_usd' => $paidBase,
                'reward_amount_usd' => $updateData['reward_amount'] ?? 0,
                'commission_earned_usd' => $updateData['commission_earned'] ?? 0,
                'status' => ReferralStatus::ACTIVE->value,
            ]);

            // Create discount BillingCredit for remaining months after the first payment.
            // The first month's discount was consumed directly in GatewayService.
            // Each remaining period is sized against the RECURRING charge (the monthly
            // price, or the monthly equivalent on a yearly cycle), not the one-time
            // onboarding fee, so "N months at X%" means X% off the current charge each
            // month — not a fee-shaped lump that inflates the later periods' discount.
            $discountDuration = max(1, (int) ($referralCode->discount_duration_months ?? 1));
            $remainingMonths = $discountDuration - 1;

            if ($remainingMonths > 0) {
                $cycle = (string) ($subscription?->billing_cycle ?? 'monthly');
                $yearlyUsd = (float) ($plan?->price_yearly_usd ?? 0) ?: $monthlyPriceUsd * 12;
                $recurringBase = $cycle === 'yearly' ? round($yearlyUsd / 12, 2) : $monthlyPriceUsd;

                $perPeriodDiscount = $this->discountAgainstBase($referralCode, $recurringBase);
                if ($perPeriodDiscount > 0) {
                    BillingCredit::create([
                        'owner_type' => 'business',
                        'owner_id' => $referral->referred_business_id,
                        'referral_id' => $referral->id,
                        'amount' => round($perPeriodDiscount * $remainingMonths, 2),
                        'amount_used' => 0,
                        'status' => 'available',
                    ]);
                }
            }

            // The code is only consumed once a payment is actually claimed with
            // it. Previewing/applying (PENDING) never counts toward max_uses.
            $referralCode->markUsed();

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
        // Sales rep code takes precedence while the rep is active. If the rep has
        // been terminated (is_active=false / code deactivated), the user falls
        // back to their personal referral code. Resolution never creates a code —
        // it returns whatever exists.
        $salesRep = SalesRep::where('user_id', $userId)->with('referralCode')->first();
        $isSalesRep = $salesRep !== null && (bool) $salesRep->is_active;

        $userCode = ($isSalesRep && $salesRep->referralCode?->isValid())
            ? $salesRep->referralCode
            : null;
        $userCode ??= ReferralCode::where('owner_user_id', $userId)
            ->where('owner_type', ReferralCodeOwnerType::BUSINESS)
            ->first();
        $userCode ??= ReferralCode::where('owner_user_id', $userId)->first();
        $userCode ??= ReferralCode::whereHas('ownerBusiness', function ($q) use ($userId) {
            $q->where('owner_id', $userId);
        })->first();

        if (!$userCode) {
            return [
                'referral_code' => null,
                'is_sales_rep' => $isSalesRep,
                'total_earned' => 0,
                'total_paid' => 0,
                'total_balance' => 0,
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
        $paidCommission = $salesRep ? (float) $salesRep->payouts()->where('status', 'paid')->sum('amount') : 0;

        $rewardsPaid = (float) User::find($userId)?->payouts()->where('status', 'paid')->sum('amount') ?? 0;

        return [
            'referral_code' => $userCode->code,
            'is_sales_rep' => $isSalesRep,
            'commission_rate' => $salesRep?->commission_rate,
            'commission_type' => $salesRep?->commission_type,
            'total_earned' => (float) $referrals->sum('reward_amount'),
            'pending_rewards' => (float) $referrals->where('status', ReferralStatus::ACTIVE)->where('reward_paid', false)->sum('reward_amount'),
            'rewarded_amount' => (float) $referrals->where('status', ReferralStatus::REWARDED)->sum('reward_amount'),
            'rewards_paid' => $rewardsPaid,
            'commission_earned' => $totalCommission,
            'commission_pending' => round(max(0, $totalCommission - $paidCommission), 2),
            'commission_paid' => $paidCommission,
            'total_referrals' => $referrals->count(),
            'active_referrals' => $referrals->where('status', ReferralStatus::ACTIVE)->count(),
            'referrals' => $referrals->toArray(),
        ];
    }
}
