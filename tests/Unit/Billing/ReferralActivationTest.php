<?php

namespace Tests\Unit\Billing;

use App\Enums\Billing\CommissionType;
use App\Enums\Billing\DiscountType;
use App\Enums\Billing\ReferralCodeOwnerType;
use App\Enums\Billing\ReferralStatus;
use App\Enums\Billing\RewardType;
use App\Models\Business;
use App\Models\BillingCredit;
use App\Models\Plan;
use App\Models\ReferralCode;
use App\Models\SalesRep;
use App\Models\Subscription;
use App\Models\User;
use App\Services\ReferralService;
use Database\Seeders\PlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReferralActivationTest extends TestCase
{
    use RefreshDatabase;
    use ReferralPricingExpectations;

    protected ReferralService $referralService;

    protected Business $business;

    protected Subscription $subscription;

    protected Plan $plan;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PlanSeeder::class);

        $user = User::factory()->create(['is_active' => true]);
        $this->business = Business::factory()->create(['owner_id' => $user->id]);

        $this->plan = Plan::where('slug', 'essential')->first();
        $plan = $this->plan;

        $this->subscription = Subscription::create([
            'business_id' => $this->business->id,
            'plan_id' => $plan->id,
            'status' => 'trial',
            'price_monthly_usd' => $plan->price_monthly_usd,
            'billing_cycle' => 'monthly',
            'starts_at' => now(),
            'trial_ends_at' => now()->addDays(14),
            'next_billing_date' => now()->addDays(14),
        ]);

        $this->referralService = app(ReferralService::class);
    }

    // ─── Scenario 2: markActive (payment confirmed) - BUSINESS codes ───

    public function test_mark_active_calculates_reward_for_business_percentage_code(): void
    {
        $referralCode = ReferralCode::create([
            'owner_type' => ReferralCodeOwnerType::BUSINESS,
            'owner_business_id' => $this->business->id,
            'code' => 'GIVE5',
            'discount_type' => DiscountType::PERCENTAGE,
            'discount_value' => 10,
            'reward_type' => RewardType::PERCENTAGE,
            'reward_value' => 5,
            'is_active' => true,
        ]);

        $referral = $this->referralService->processReferral(
            $referralCode->code,
            $this->subscription->id,
            $this->business->id
        );

        $this->assertEquals(0, (float) $referral->reward_amount);

        $activated = $this->referralService->markActive($referral->id);

        // Price-agnostic: reward = 5% of the amount actually paid (base − discount).
        $discount = $this->referralDiscountApplied($referralCode, $this->plan, $this->subscription);
        $paidBase = $this->referralPaidBase($this->plan, $this->subscription, $discount);
        $expectedReward = round($paidBase * 0.05, 2);

        $this->assertEquals(ReferralStatus::ACTIVE, $activated->status);
        $this->assertEquals($expectedReward, (float) $activated->reward_amount, '5% of amount actually paid after discount');
        $this->assertNotNull($activated->converted_at);
    }

    public function test_mark_active_calculates_reward_for_business_flat_code(): void
    {
        $referralCode = ReferralCode::create([
            'owner_type' => ReferralCodeOwnerType::BUSINESS,
            'owner_business_id' => $this->business->id,
            'code' => 'FLAT10',
            'discount_type' => DiscountType::PERCENTAGE,
            'discount_value' => 10,
            'reward_type' => RewardType::FLAT_AMOUNT,
            'reward_value' => 10,
            'is_active' => true,
        ]);

        $referral = $this->referralService->processReferral(
            $referralCode->code,
            $this->subscription->id,
            $this->business->id
        );

        $activated = $this->referralService->markActive($referral->id);

        // Flat reward = 10, but the safe zone caps it below 50% of the paid base.
        $discount = $this->referralDiscountApplied($referralCode, $this->plan, $this->subscription);
        $paidBase = $this->referralPaidBase($this->plan, $this->subscription, $discount);
        $expected = min(10.0, $this->referralSafeZoneCap($paidBase));

        $this->assertEquals($expected, (float) $activated->reward_amount, 'flat reward = 10 when below the 50% safe-zone cap');
    }

    public function test_mark_active_caps_business_flat_reward_below_half_of_paid_base(): void
    {
        // A flat reward is hard-capped below 50% of the paid base ($36 paid →
        // max $17.99), so a legacy $50 flat reward can not break the safe zone.
        $referralCode = ReferralCode::create([
            'owner_type' => ReferralCodeOwnerType::BUSINESS,
            'owner_business_id' => $this->business->id,
            'code' => 'FLAT50',
            'discount_type' => DiscountType::PERCENTAGE,
            'discount_value' => 10,
            'reward_type' => RewardType::FLAT_AMOUNT,
            'reward_value' => 50,
            'is_active' => true,
        ]);

        $referral = $this->referralService->processReferral(
            $referralCode->code,
            $this->subscription->id,
            $this->business->id
        );

        $activated = $this->referralService->markActive($referral->id);

        // A flat reward is hard-capped below 50% of the paid base, so a legacy
        // $50 flat reward can not break the safe zone - whatever the plan price.
        $discount = $this->referralDiscountApplied($referralCode, $this->plan, $this->subscription);
        $paidBase = $this->referralPaidBase($this->plan, $this->subscription, $discount);
        $expected = $this->referralSafeZoneCap($paidBase);

        $this->assertEquals(
            $expected,
            (float) $activated->reward_amount,
            'flat reward capped to just under 50% of the paid base (never >= half)'
        );
    }

    public function test_mark_active_calculates_reward_for_business_free_month_code(): void
    {
        $referralCode = ReferralCode::create([
            'owner_type' => ReferralCodeOwnerType::BUSINESS,
            'owner_business_id' => $this->business->id,
            'code' => 'FREEMONTH',
            'discount_type' => DiscountType::FREE_MONTH,
            'discount_value' => 0,
            'reward_type' => RewardType::FREE_MONTH,
            'reward_value' => 0,
            'is_active' => true,
        ]);

        $referral = $this->referralService->processReferral(
            $referralCode->code,
            $this->subscription->id,
            $this->business->id
        );

        $activated = $this->referralService->markActive($referral->id);

        $this->assertEquals(0.00, (float) $activated->reward_amount, 'free_month reward = 0 when the referee pays nothing');
    }

    public function test_mark_active_free_month_reward_capped_at_recurring_monthly_not_full_paid_base(): void
    {
        $referralCode = ReferralCode::create([
            'owner_type' => ReferralCodeOwnerType::BUSINESS,
            'owner_business_id' => $this->business->id,
            'code' => 'FREEMTHCAP',
            'discount_type' => DiscountType::PERCENTAGE,
            'discount_value' => 10,
            'reward_type' => RewardType::FREE_MONTH,
            'reward_value' => 0,
            'is_active' => true,
        ]);

        $referral = $this->referralService->processReferral(
            $referralCode->code,
            $this->subscription->id,
            $this->business->id
        );

        // Price-agnostic: a free-month reward pays ONE month of the plan
        // (recurring monthly), but the safe zone caps any reward strictly below
        // half the paid base.
        $activated = $this->referralService->markActive($referral->id);

        $discount = $this->referralDiscountApplied($referralCode, $this->plan, $this->subscription);
        $paidBase = $this->referralPaidBase($this->plan, $this->subscription, $discount);
        $expected = $this->referralSafeZoneCap($paidBase);

        $this->assertEquals(
            $expected,
            (float) $activated->reward_amount,
            'free_month reward capped just under 50% of the paid base'
        );
    }

    public function test_mark_active_free_month_reward_never_exceeds_paid_base(): void
    {
        // A yearly plan spreads its price across 12 months; the monthly
        // equivalent must still never exceed what the referee actually paid.
        $referralCode = ReferralCode::create([
            'owner_type' => ReferralCodeOwnerType::BUSINESS,
            'owner_business_id' => $this->business->id,
            'code' => 'FREEMTHYEAR',
            'discount_type' => DiscountType::PERCENTAGE,
            'discount_value' => 10,
            'reward_type' => RewardType::FREE_MONTH,
            'reward_value' => 0,
            'is_active' => true,
        ]);

        $this->subscription->update([
            'billing_cycle' => 'yearly',
        ]);

        $referral = $this->referralService->processReferral(
            $referralCode->code,
            $this->subscription->id,
            $this->business->id
        );

        // Price-agnostic: paid base is base − discount, whatever the plan price.
        $discount = $this->referralDiscountApplied($referralCode, $this->plan, $this->subscription);
        $paidBase = $this->referralPaidBase($this->plan, $this->subscription, $discount);
        $activated = $this->referralService->markActive($referral->id);

        $this->assertLessThanOrEqual(
            $paidBase,
            (float) $activated->reward_amount,
            'reward must never exceed the amount the referee actually paid'
        );
    }

    public function test_mark_active_creates_remaining_months_credit_off_recurring_charge(): void
    {
        $referralCode = ReferralCode::create([
            'owner_type' => ReferralCodeOwnerType::BUSINESS,
            'owner_business_id' => $this->business->id,
            'code' => 'MULTI3',
            'discount_type' => DiscountType::PERCENTAGE,
            'discount_value' => 20,
            'discount_duration_months' => 3,
            'reward_type' => RewardType::PERCENTAGE,
            'reward_value' => 5,
            'is_active' => true,
        ]);

        $referral = $this->referralService->processReferral(
            $referralCode->code,
            $this->subscription->id,
            $this->business->id
        );

        $this->referralService->markActive($referral->id);

        // First charge (no onboarding fee, so monthly base) took 20% off.
        // The remaining 2 months are charged at the RECURRING monthly price, so
        // each must be 20% × monthly → credit = 2 × that.
        $recurringMonthly = (float) $this->plan->price_monthly_usd;
        $perPeriod = round($recurringMonthly * 0.20, 2);
        $expectedCredit = round($perPeriod * 2, 2);

        $credit = BillingCredit::where('referral_id', $referral->id)
            ->where('owner_id', $this->business->id)
            ->orderByDesc('id')
            ->first();

        $this->assertNotNull($credit, 'remaining-months credit should exist');
        $this->assertEquals($expectedCredit, (float) $credit->amount, '2 remaining months × 20% of recurring monthly');
    }

    // ─── Scenario 2b: markActive - CAMPAIGN codes earn no reward ───

    public function test_mark_active_earns_no_reward_for_campaign_code(): void
    {
        $referralCode = ReferralCode::create([
            'owner_type' => ReferralCodeOwnerType::CAMPAIGN,
            'owner_user_id' => User::factory()->create(['is_active' => true])->id,
            'code' => 'CAMPAIGNNO',
            'discount_type' => DiscountType::PERCENTAGE,
            'discount_value' => 10,
            'discount_duration_months' => 1,
            'reward_type' => RewardType::PERCENTAGE,
            'reward_value' => 25,
            'is_active' => true,
        ]);

        $referral = $this->referralService->processReferral(
            $referralCode->code,
            $this->subscription->id,
            $this->business->id
        );

        $activated = $this->referralService->markActive($referral->id);

        $this->assertEquals(0, (float) $activated->reward_amount, 'campaign codes should not reward the company');
        $this->assertSame(
            0,
            BillingCredit::where('referral_id', $referral->id)->count(),
            'no reward credit should exist for a campaign code'
        );
    }

    // ─── Scenario 3: markActive (payment confirmed) - SALES_REP codes ───

    public function test_mark_active_calculates_commission_for_sales_rep_percentage(): void
    {
        $owner = User::factory()->create(['is_active' => true]);
        $salesRepCode = ReferralCode::create([
            'owner_type' => ReferralCodeOwnerType::SALES_REP,
            'owner_user_id' => $owner->id,
            'code' => 'SR10',
            'discount_type' => DiscountType::PERCENTAGE,
            'discount_value' => 10,
            'reward_type' => RewardType::PERCENTAGE,
            'reward_value' => 5,
            'is_active' => true,
        ]);

        $salesRep = SalesRep::create([
            'user_id' => $owner->id,
            'referral_code_id' => $salesRepCode->id,
            'commission_rate' => 10,
            'commission_type' => CommissionType::PERCENTAGE,
            'is_active' => true,
        ]);

        $referral = $this->referralService->processReferral(
            $salesRepCode->code,
            $this->subscription->id,
            $this->business->id
        );

        $activated = $this->referralService->markActive($referral->id);

        // 10% commission of the amount actually paid (base − discount).
        $discount = $this->referralDiscountApplied($salesRepCode, $this->plan, $this->subscription);
        $paidBase = $this->referralPaidBase($this->plan, $this->subscription, $discount);
        $expectedCommission = round($paidBase * 0.10, 2);

        $this->assertEquals($expectedCommission, (float) $activated->commission_earned, '10% of amount actually paid after discount');
        $this->assertEquals(0, (float) $activated->reward_amount, 'sales rep earns commission only, not reward');
    }

    public function test_mark_active_calculates_commission_for_sales_rep_flat(): void
    {
        $owner = User::factory()->create(['is_active' => true]);
        $salesRepCode = ReferralCode::create([
            'owner_type' => ReferralCodeOwnerType::SALES_REP,
            'owner_user_id' => $owner->id,
            'code' => 'SR10',
            'discount_type' => DiscountType::PERCENTAGE,
            'discount_value' => 10,
            'reward_type' => RewardType::FLAT_AMOUNT,
            'reward_value' => 0,
            'is_active' => true,
        ]);

        $salesRep = SalesRep::create([
            'user_id' => $owner->id,
            'referral_code_id' => $salesRepCode->id,
            'commission_rate' => 10,
            'commission_type' => CommissionType::FLAT,
            'is_active' => true,
        ]);

        $referral = $this->referralService->processReferral(
            $salesRepCode->code,
            $this->subscription->id,
            $this->business->id
        );

        $activated = $this->referralService->markActive($referral->id);

        // Flat commission = 10, but the safe zone caps it below 50% of the paid base.
        $discount = $this->referralDiscountApplied($salesRepCode, $this->plan, $this->subscription);
        $paidBase = $this->referralPaidBase($this->plan, $this->subscription, $discount);
        $expected = min(10.0, $this->referralSafeZoneCap($paidBase));

        $this->assertEquals($expected, (float) $activated->commission_earned, 'flat commission = 10 capped by the 50% safe zone');
        $this->assertEquals(0, (float) $activated->reward_amount, 'flat reward = 0');
    }

    public function test_mark_active_no_commission_for_inactive_sales_rep(): void
    {
        $owner = User::factory()->create(['is_active' => true]);
        $salesRepCode = ReferralCode::create([
            'owner_type' => ReferralCodeOwnerType::SALES_REP,
            'owner_user_id' => $owner->id,
            'code' => 'INACTIVE',
            'discount_type' => DiscountType::PERCENTAGE,
            'discount_value' => 10,
            'reward_type' => RewardType::PERCENTAGE,
            'reward_value' => 5,
            'is_active' => true,
        ]);

        SalesRep::create([
            'user_id' => $owner->id,
            'referral_code_id' => $salesRepCode->id,
            'commission_rate' => 10,
            'commission_type' => CommissionType::PERCENTAGE,
            'is_active' => false,
        ]);

        $referral = $this->referralService->processReferral(
            $salesRepCode->code,
            $this->subscription->id,
            $this->business->id
        );

        $activated = $this->referralService->markActive($referral->id);

        $this->assertNull($activated->commission_earned, 'inactive sales rep should not earn commission');
        $this->assertEquals(0, (float) $activated->reward_amount, 'sales rep code earns commission only, no fallback reward');
    }
}
