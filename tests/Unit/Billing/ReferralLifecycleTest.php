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
use App\Services\SubscriptionService;
use Database\Seeders\PlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReferralLifecycleTest extends TestCase
{
    use RefreshDatabase;

    protected ReferralService $referralService;

    protected SubscriptionService $subscriptionService;

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
            'price_monthly' => 100000,
            'billing_cycle' => 'monthly',
            'starts_at' => now(),
            'trial_ends_at' => now()->addDays(14),
            'next_billing_date' => now()->addDays(14),
        ]);

        $this->referralService = app(ReferralService::class);
        $this->subscriptionService = app(SubscriptionService::class);
    }

    // ─── Scenario 1: processReferral (code applied) ───

    public function test_process_referral_sets_reward_amount_to_zero(): void
    {
        $referralCode = ReferralCode::create([
            'owner_type' => ReferralCodeOwnerType::BUSINESS,
            'owner_business_id' => $this->business->id,
            'code' => 'GIVE10',
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

        $this->assertEquals(ReferralStatus::PENDING, $referral->status);
        $this->assertEquals(0, (float) $referral->reward_amount, 'reward_amount should be 0 before payment');
        $this->assertNotNull($referral->discount_applied, 'discount_applied should be set');
    }

    public function test_process_referral_for_sales_rep_code_sets_reward_amount_to_zero(): void
    {
        $owner = User::factory()->create(['is_active' => true]);
        $salesRepCode = ReferralCode::create([
            'owner_type' => ReferralCodeOwnerType::SALES_REP,
            'owner_user_id' => $owner->id,
            'code' => 'SR10',
            'discount_type' => DiscountType::FLAT_AMOUNT,
            'discount_value' => 5000,
            'reward_type' => RewardType::FLAT_AMOUNT,
            'reward_value' => 10000,
            'is_active' => true,
        ]);

        $referral = $this->referralService->processReferral(
            $salesRepCode->code,
            $this->subscription->id,
            $this->business->id
        );

        $this->assertEquals(0, (float) $referral->reward_amount, 'reward_amount should be 0 before payment');
        $this->assertNotNull($referral->discount_applied, 'discount_applied should still be set');
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
        $this->assertEquals(5000, (float) $activated->reward_amount, '5% of 100000 = 5000');
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

        $this->assertEquals(100000, (float) $activated->reward_amount, 'free_month reward = monthly price');
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

        $this->assertEquals(10000, (float) $activated->commission_earned, '10% of 100000 = 10000');
        $this->assertEquals(5000, (float) $activated->reward_amount, '5% reward = 5000');
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
        $this->assertEquals(5000, (float) $activated->reward_amount, 'reward still calculated for referrer');
    }

    // ─── Scenario 4: activateForSubscription triggers markActive ───

    public function test_activate_for_subscription_triggers_mark_active(): void
    {
        $referralCode = ReferralCode::create([
            'owner_type' => ReferralCodeOwnerType::BUSINESS,
            'owner_business_id' => $this->business->id,
            'code' => 'AUTO',
            'discount_type' => DiscountType::PERCENTAGE,
            'discount_value' => 10,
            'reward_type' => RewardType::FLAT_AMOUNT,
            'reward_value' => 15000,
            'is_active' => true,
        ]);

        $referral = $this->referralService->processReferral(
            $referralCode->code,
            $this->subscription->id,
            $this->business->id
        );

        $this->assertEquals(0, (float) $referral->reward_amount);
        $this->assertEquals(ReferralStatus::PENDING, $referral->status);

        $this->referralService->activateForSubscription($this->subscription->id);

        $referral->refresh();
        $this->assertEquals(ReferralStatus::ACTIVE, $referral->status);
        $this->assertEquals(15000, (float) $referral->reward_amount, 'reward calculated on activation');
    }

    public function test_activate_for_subscription_skips_already_active(): void
    {
        $referralCode = ReferralCode::create([
            'owner_type' => ReferralCodeOwnerType::BUSINESS,
            'owner_business_id' => $this->business->id,
            'code' => 'SKIP',
            'discount_type' => DiscountType::PERCENTAGE,
            'discount_value' => 10,
            'reward_type' => RewardType::FLAT_AMOUNT,
            'reward_value' => 15000,
            'is_active' => true,
        ]);

        $referral = $this->referralService->processReferral(
            $referralCode->code,
            $this->subscription->id,
            $this->business->id
        );

        $this->referralService->markActive($referral->id);
        $referral->refresh();
        $this->assertEquals(ReferralStatus::ACTIVE, $referral->status);

        $this->referralService->activateForSubscription($this->subscription->id);
        $referral->refresh();
        $this->assertEquals(15000, (float) $referral->reward_amount, 'should not double-calculate');
    }

    // ─── Scenario 5: renewSubscription does NOT touch referrals ───

    public function test_renew_subscription_does_not_touch_referral(): void
    {
        $referralCode = ReferralCode::create([
            'owner_type' => ReferralCodeOwnerType::BUSINESS,
            'owner_business_id' => $this->business->id,
            'code' => 'NOCHANGE',
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

        $this->referralService->markActive($referral->id);
        $referral->refresh();
        $originalReward = (float) $referral->reward_amount;
        $originalConvertedAt = $referral->converted_at;

        $this->subscription->update(['status' => 'active']);
        $this->subscriptionService->renewSubscription($this->subscription);

        $referral->refresh();
        $this->assertEquals($originalReward, (float) $referral->reward_amount, 'reward should not change on renewal');
        $this->assertEquals($originalConvertedAt->timestamp, $referral->converted_at->timestamp, 'converted_at should not change');
    }

    // ─── Scenario 6: scopeUnpaid ───

    public function test_scope_unpaid_finds_active_unpaid_referrals(): void
    {
        $referralCode = ReferralCode::create([
            'owner_type' => ReferralCodeOwnerType::BUSINESS,
            'owner_business_id' => $this->business->id,
            'code' => 'UNPAID1',
            'discount_type' => DiscountType::PERCENTAGE,
            'discount_value' => 10,
            'reward_type' => RewardType::FLAT_AMOUNT,
            'reward_value' => 10000,
            'is_active' => true,
        ]);

        $referral = $this->referralService->processReferral(
            $referralCode->code,
            $this->subscription->id,
            $this->business->id
        );

        $this->assertDatabaseCount('referrals', 1);

        $unpaid = \App\Models\Referral::unpaid()->get();
        $this->assertCount(0, $unpaid, 'PENDING referral should not appear in unpaid');

        $this->referralService->markActive($referral->id);

        $unpaid = \App\Models\Referral::unpaid()->get();
        $this->assertCount(1, $unpaid, 'ACTIVE referral should appear in unpaid');
        $this->assertEquals($referral->id, $unpaid->first()->id);
    }

    public function test_scope_unpaid_excludes_rewarded_referrals(): void
    {
        $referralCode = ReferralCode::create([
            'owner_type' => ReferralCodeOwnerType::BUSINESS,
            'owner_business_id' => $this->business->id,
            'code' => 'PAID1',
            'discount_type' => DiscountType::PERCENTAGE,
            'discount_value' => 10,
            'reward_type' => RewardType::FLAT_AMOUNT,
            'reward_value' => 10000,
            'is_active' => true,
        ]);

        $referral = $this->referralService->processReferral(
            $referralCode->code,
            $this->subscription->id,
            $this->business->id
        );

        $this->referralService->markActive($referral->id);
        $this->referralService->markRewarded($referral->id);

        $unpaid = \App\Models\Referral::unpaid()->get();
        $this->assertCount(0, $unpaid, 'REWARDED referral should not appear in unpaid');
    }

    // ─── Scenario 7: markRewarded ───

    public function test_mark_rewarded_sets_status_and_reward_paid(): void
    {
        $referralCode = ReferralCode::create([
            'owner_type' => ReferralCodeOwnerType::BUSINESS,
            'owner_business_id' => $this->business->id,
            'code' => 'REW1',
            'discount_type' => DiscountType::PERCENTAGE,
            'discount_value' => 10,
            'reward_type' => RewardType::FLAT_AMOUNT,
            'reward_value' => 10000,
            'is_active' => true,
        ]);

        $referral = $this->referralService->processReferral(
            $referralCode->code,
            $this->subscription->id,
            $this->business->id
        );

        $this->referralService->markActive($referral->id);
        $rewarded = $this->referralService->markRewarded($referral->id);

        $this->assertEquals(ReferralStatus::REWARDED, $rewarded->status);
        $this->assertTrue($rewarded->reward_paid);
        $this->assertEquals(10000, (float) $rewarded->reward_amount, 'reward_amount should persist after rewarding');
    }

    // ─── Scenario 8: getEarningsByUser ───

    public function test_get_earnings_by_user_returns_correct_totals(): void
    {
        $owner = User::factory()->create(['is_active' => true]);

        $referralCode = ReferralCode::create([
            'owner_type' => ReferralCodeOwnerType::BUSINESS,
            'owner_user_id' => $owner->id,
            'owner_business_id' => null,
            'code' => 'EARN1',
            'discount_type' => DiscountType::PERCENTAGE,
            'discount_value' => 10,
            'reward_type' => RewardType::FLAT_AMOUNT,
            'reward_value' => 10000,
            'is_active' => true,
        ]);

        $referral = $this->referralService->processReferral(
            $referralCode->code,
            $this->subscription->id,
            $this->business->id
        );

        $earnings = $this->referralService->getEarningsByUser($owner->id);
        $this->assertEquals(0, $earnings['total_earned'], 'pending referral has 0 reward');

        $this->referralService->markActive($referral->id);

        $earnings = $this->referralService->getEarningsByUser($owner->id);
        $this->assertEquals(10000, $earnings['total_earned'], 'active referral has reward');
        $this->assertEquals(10000, $earnings['pending_rewards'], 'active referral is pending');
        $this->assertEquals(0, $earnings['rewarded_amount'], 'not rewarded yet');

        $this->referralService->markRewarded($referral->id);

        $earnings = $this->referralService->getEarningsByUser($owner->id);
        $this->assertEquals(10000, $earnings['total_earned']);
        $this->assertEquals(0, $earnings['pending_rewards'], 'rewarded referral not pending');
        $this->assertEquals(10000, $earnings['rewarded_amount'], 'rewarded amount counted');
    }

    public function test_get_earnings_by_user_returns_zero_for_non_rep(): void
    {
        $randomUser = User::factory()->create(['is_active' => true]);

        $earnings = $this->referralService->getEarningsByUser($randomUser->id);

        $this->assertEquals(0, $earnings['total_earned']);
        $this->assertEquals(0, $earnings['pending_rewards']);
        $this->assertEquals(0, $earnings['rewarded_amount']);
        $this->assertNull($earnings['referral_code']);
        $this->assertFalse($earnings['is_sales_rep']);
    }

    // ─── Scenario 9: Self-referral prevention ───

    public function test_business_cannot_use_own_referral_code(): void
    {
        $owner = User::factory()->create(['is_active' => true]);
        $ownBusiness = Business::factory()->create(['owner_id' => $owner->id]);

        $referralCode = ReferralCode::create([
            'owner_type' => ReferralCodeOwnerType::BUSINESS,
            'owner_user_id' => $owner->id,
            'owner_business_id' => $ownBusiness->id,
            'code' => 'SELF1',
            'discount_type' => DiscountType::PERCENTAGE,
            'discount_value' => 10,
            'is_active' => true,
        ]);

        $sub = Subscription::create([
            'business_id' => $ownBusiness->id,
            'plan_id' => $this->subscription->plan_id,
            'status' => 'trial',
            'billing_cycle' => 'monthly',
            'starts_at' => now(),
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('cannot use your own referral code');

        $this->referralService->processReferral(
            $referralCode->code,
            $sub->id,
            $ownBusiness->id
        );
    }
}
