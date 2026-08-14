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

    protected ReferralService $referralService;

    protected Business $business;

    protected Subscription $subscription;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PlanSeeder::class);

        $user = User::factory()->create(['is_active' => true]);
        $this->business = Business::factory()->create(['owner_id' => $user->id]);

        $plan = Plan::where('slug', 'essential')->first();

        $this->subscription = Subscription::create([
            'business_id' => $this->business->id,
            'plan_id' => $plan->id,
            'status' => 'trial',
            'price_monthly_usd' => 25,
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

        $this->assertEquals(ReferralStatus::ACTIVE, $activated->status);
        $this->assertEquals(1.80, (float) $activated->reward_amount, '5% of amount paid ($40 onboarding - $4 discount = $36) = $1.80');
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

        $this->assertEquals(10.00, (float) $activated->reward_amount, 'flat reward = 10 when below the 50% safe-zone cap');
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

        $this->assertEquals(
            17.99,
            (float) $activated->reward_amount,
            'flat reward capped to just under 50% of the $36 paid base (never >= half)'
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

        // Referee pays $36 ($40 onboarding − 10%). A free-month reward pays ONE
        // month of the plan ($20 monthly), but the safe zone caps any reward
        // strictly below half the paid base ($36 / 2 = $18) → $17.99.
        $activated = $this->referralService->markActive($referral->id);

        $this->assertEquals(
            17.99,
            (float) $activated->reward_amount,
            'free_month reward = min(recurring monthly $20, paid base $36) = $20, then capped just under 50% of the paid base'
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
            'price_monthly_usd' => 20,
        ]);

        $referral = $this->referralService->processReferral(
            $referralCode->code,
            $this->subscription->id,
            $this->business->id
        );

        $paidBase = 40.0 - 4.0;
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

        // First charge (onboarding fee $40) took 20% off = $8.
        // The remaining 2 months are charged at the RECURRING monthly price
        // ($20), so each must be 20% × $20 = $4 → credit = $8 total.
        $credit = BillingCredit::where('referral_id', $referral->id)
            ->where('owner_id', $this->business->id)
            ->orderByDesc('id')
            ->first();

        $this->assertNotNull($credit, 'remaining-months credit should exist');
        $this->assertEquals(8.00, (float) $credit->amount, '2 remaining months × 20% of $20 recurring = $8');
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

        $this->assertEquals(3.60, (float) $activated->commission_earned, '10% of amount paid ($40 - $4 discount = $36) = $3.60');
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

        $this->assertEquals(10.00, (float) $activated->commission_earned, 'flat commission = 10');
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
