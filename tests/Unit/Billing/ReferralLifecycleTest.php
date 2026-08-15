<?php

namespace Tests\Unit\Billing;

use App\Enums\Billing\CommissionType;
use App\Enums\Billing\DiscountType;
use App\Enums\Billing\ReferralCodeOwnerType;
use App\Enums\Billing\ReferralStatus;
use App\Enums\Billing\RewardType;
use App\Models\Business;
use App\Models\Payout;
use App\Models\Plan;
use App\Models\Referral;
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
    use ReferralPricingExpectations;

    protected ReferralService $referralService;

    protected SubscriptionService $subscriptionService;

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
            'reward_value' => 5,
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
        $this->assertEquals(5.00, (float) $referral->reward_amount, 'flat reward calculated on activation');
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
        $this->assertEquals(ReferralStatus::ACTIVE, $referral->status);

        $this->referralService->activateForSubscription($this->subscription->id);
        $referral->refresh();
        $this->assertEquals(5.00, (float) $referral->reward_amount, 'should not double-calculate');
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
            'reward_value' => 5,
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
        $this->assertEquals(5.00, (float) $rewarded->reward_amount, 'reward_amount should persist after rewarding');
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
            'reward_value' => 5,
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
        $this->assertEquals(5.00, $earnings['total_earned'], 'active referral has reward');
        $this->assertEquals(5.00, $earnings['pending_rewards'], 'active referral is pending');
        $this->assertEquals(0, $earnings['rewarded_amount'], 'not rewarded yet');

        $this->referralService->markRewarded($referral->id);

        $earnings = $this->referralService->getEarningsByUser($owner->id);
        $this->assertEquals(5.00, $earnings['total_earned']);
        $this->assertEquals(0, $earnings['pending_rewards'], 'rewarded referral not pending');
        $this->assertEquals(5.00, $earnings['rewarded_amount'], 'rewarded amount counted');
    }

    public function test_deleting_referred_business_does_not_reset_referrer_earnings(): void
    {
        $referrer = User::factory()->create(['is_active' => true]);

        $referralCode = ReferralCode::create([
            'owner_type' => ReferralCodeOwnerType::BUSINESS,
            'owner_user_id' => $referrer->id,
            'owner_business_id' => null,
            'code' => 'KEEP1',
            'discount_type' => DiscountType::PERCENTAGE,
            'discount_value' => 10,
            'reward_type' => RewardType::FLAT_AMOUNT,
            'reward_value' => 5,
            'is_active' => true,
        ]);

        $referral = $this->referralService->processReferral(
            $referralCode->code,
            $this->subscription->id,
            $this->business->id
        );

        $this->referralService->markActive($referral->id);

        $this->assertEquals(5.00, (float) Referral::find($referral->id)->reward_amount);

        // Platform hard-delete wipes the referred business. The referral row is
        // an earnings ledger record for the referrer and must not be cascade
        // deleted with it - deleting a referred business must NOT reset earnings.
        $this->business->forceDelete();

        $this->assertDatabaseHas('referrals', ['id' => $referral->id]);
        $this->assertDatabaseHas('referrals', [
            'id' => $referral->id,
            'referred_business_id' => null,
        ]);

        $earnings = $this->referralService->getEarningsByUser($referrer->id);
        $this->assertEquals(5.00, $earnings['total_earned'], 'earnings survive deleting the referred business');
        $this->assertEquals(1, $earnings['total_referrals'], 'referral count survives deleting the referred business');
        $this->assertEquals(5.00, $earnings['pending_rewards'], 'reward remains pending/payable');
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

    public function test_get_earnings_by_user_counts_only_paid_commission_for_sales_rep(): void
    {
        $owner = User::factory()->create(['is_active' => true]);
        $salesRepCode = ReferralCode::create([
            'owner_type' => ReferralCodeOwnerType::SALES_REP,
            'owner_user_id' => $owner->id,
            'code' => 'SRC1',
            'discount_type' => DiscountType::PERCENTAGE,
            'discount_value' => 10,
            'reward_type' => RewardType::FLAT_AMOUNT,
            'reward_value' => 0,
            'is_active' => true,
        ]);

        $salesRep = SalesRep::create([
            'user_id' => $owner->id,
            'referral_code_id' => $salesRepCode->id,
            'commission_rate' => 30,
            'commission_type' => CommissionType::PERCENTAGE,
            'is_active' => true,
        ]);

        $referral = $this->referralService->processReferral(
            $salesRepCode->code,
            $this->subscription->id,
            $this->business->id
        );
        $this->referralService->markActive($referral->id);
        $referral->refresh();

        // 30% commission of the amount actually paid (base − 10% discount).
        $discount = $this->referralDiscountApplied($salesRepCode, $this->plan, $this->subscription);
        $paidBase = $this->referralPaidBase($this->plan, $this->subscription, $discount);
        $expectedEarned = round($paidBase * 0.30, 2);

        // A paid payout should count as commission paid...
        $paidPayout = round($expectedEarned / 2, 2);
        $salesRep->payouts()->create([
            'amount' => $paidPayout,
            'currency' => 'USD',
            'status' => 'paid',
            'paid_at' => now(),
        ]);
        // ...but a scheduled payout must NOT (pending stays intact).
        $salesRep->payouts()->create([
            'amount' => 20.00,
            'currency' => 'USD',
            'status' => 'scheduled',
            'scheduled_at' => now()->addDays(7),
        ]);

        $earnings = $this->referralService->getEarningsByUser($owner->id);

        $this->assertTrue($earnings['is_sales_rep']);
        $this->assertEquals($paidPayout, $earnings['commission_paid'], 'scheduled payout must not count as paid');
        $this->assertGreaterThan($paidPayout, $earnings['commission_earned'], 'commission earned covers total earned');
        $this->assertEquals(
            round($earnings['commission_earned'] - $paidPayout, 2),
            $earnings['commission_pending'],
            'pending = earned - paid only'
        );
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
