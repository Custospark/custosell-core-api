<?php

namespace Tests\Unit\Billing;

use App\Enums\Billing\CommissionType;
use App\Enums\Billing\DiscountType;
use App\Enums\Billing\ReferralCodeOwnerType;
use App\Enums\Billing\ReferralStatus;
use App\Enums\Billing\RewardType;
use App\Models\Business;
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

    // ─── Scenario 2: markActive (payment confirmed) — BUSINESS codes ───

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
            'code' => 'FLAT20K',
            'discount_type' => DiscountType::FLAT_AMOUNT,
            'discount_value' => 5000,
            'reward_type' => RewardType::FLAT_AMOUNT,
            'reward_value' => 20000,
            'is_active' => true,
        ]);

        $referral = $this->referralService->processReferral(
            $referralCode->code,
            $this->subscription->id,
            $this->business->id
        );

        $activated = $this->referralService->markActive($referral->id);

        $this->assertEquals(20000, (float) $activated->reward_amount, 'flat reward = 20000');
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

    // ─── Scenario 3: markActive (payment confirmed) — SALES_REP codes ───

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
            'code' => 'SR50K',
            'discount_type' => DiscountType::FLAT_AMOUNT,
            'discount_value' => 5000,
            'reward_type' => RewardType::FLAT_AMOUNT,
            'reward_value' => 0,
            'is_active' => true,
        ]);

        $salesRep = SalesRep::create([
            'user_id' => $owner->id,
            'referral_code_id' => $salesRepCode->id,
            'commission_rate' => 50000,
            'commission_type' => CommissionType::FLAT,
            'is_active' => true,
        ]);

        $referral = $this->referralService->processReferral(
            $salesRepCode->code,
            $this->subscription->id,
            $this->business->id
        );

        $activated = $this->referralService->markActive($referral->id);

        $this->assertEquals(50000, (float) $activated->commission_earned, 'flat commission = 50000');
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
