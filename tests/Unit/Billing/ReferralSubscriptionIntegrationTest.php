<?php

namespace Tests\Unit\Billing;

use App\Enums\Billing\CommissionType;
use App\Enums\Billing\DiscountType;
use App\Enums\Billing\ReferralCodeOwnerType;
use App\Enums\Billing\ReferralStatus;
use App\Enums\Billing\RewardType;
use App\Enums\Billing\SubscriptionStatus;
use App\Models\Business;
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

class ReferralSubscriptionIntegrationTest extends TestCase
{
    use RefreshDatabase;

    protected ReferralService $referralService;
    protected SubscriptionService $subscriptionService;
    protected Plan $essential;
    protected Plan $professional;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PlanSeeder::class);

        $this->essential = Plan::where('slug', 'essential')->first();
        $this->professional = Plan::where('slug', 'professional')->first();

        $this->referralService = app(ReferralService::class);
        $this->subscriptionService = app(SubscriptionService::class);
    }

    private function makeReferrer(string $code, DiscountType $discountType, float|int $discountValue, RewardType $rewardType = RewardType::FLAT_AMOUNT, float|int $rewardValue = 5000): ReferralCode
    {
        $owner = User::factory()->create(['is_active' => true]);
        $business = Business::factory()->create(['owner_id' => $owner->id]);
        return ReferralCode::create([
            'owner_type' => ReferralCodeOwnerType::BUSINESS,
            'owner_business_id' => $business->id,
            'code' => $code,
            'discount_type' => $discountType,
            'discount_value' => $discountValue,
            'reward_type' => $rewardType,
            'reward_value' => $rewardValue,
            'is_active' => true,
        ]);
    }

    private function makeReferredBusiness(): Business
    {
        $user = User::factory()->create(['is_active' => true]);
        return Business::factory()->create(['owner_id' => $user->id]);
    }

    // ─── Story 1: Percentage discount — discount_applied is calculated, price_monthly_usd unchanged ───

    public function test_percentage_discount_applied_does_not_change_subscription_price(): void
    {
        $code = $this->makeReferrer('PCT10', DiscountType::PERCENTAGE, 10);
        $business = $this->makeReferredBusiness();

        $subscription = $this->subscriptionService->subscribe(
            $business->id, $this->essential->id, 'monthly', $code->code
        );

        $referral = Referral::where('subscription_id', $subscription->id)->first();

        $this->assertNotNull($referral, 'Referral record must be created during subscribe');
        $expected = round($this->essential->onboarding_fee_usd * 10 / 100, 2);
        $this->assertEquals($expected, (float) $referral->discount_applied);
        $this->assertEquals(
            $this->essential->price_monthly_usd,
            (float) $subscription->price_monthly_usd,
            'Subscription price_monthly_usd must remain unchanged — referral discount is informational only'
        );
    }

    // ─── Story 2: Flat discount — discount_applied is calculated, price_monthly_usd unchanged ───

    public function test_flat_discount_applied_does_not_change_subscription_price(): void
    {
        $code = $this->makeReferrer('FLAT10', DiscountType::FLAT_AMOUNT, 10);

        $business = $this->makeReferredBusiness();
        $subscription = $this->subscriptionService->subscribe(
            $business->id, $this->professional->id, 'monthly', $code->code
        );

        $referral = Referral::where('subscription_id', $subscription->id)->first();

        $this->assertNotNull($referral);
        $this->assertEquals(10, (float) $referral->discount_applied);
        $this->assertEquals(
            $this->professional->price_monthly_usd,
            (float) $subscription->price_monthly_usd,
            'Flat discount must not reduce the subscription price'
        );
    }

    // ─── Story 3: Free month discount — discount_applied equals price, price_monthly_usd unchanged ───

    public function test_free_month_discount_applied_does_not_change_subscription_price(): void
    {
        $code = $this->makeReferrer('FREEMO', DiscountType::FREE_MONTH, 0);
        $business = $this->makeReferredBusiness();

        $subscription = $this->subscriptionService->subscribe(
            $business->id, $this->essential->id, 'monthly', $code->code
        );

        $referral = Referral::where('subscription_id', $subscription->id)->first();

        $this->assertNotNull($referral);
        $this->assertEquals($this->essential->onboarding_fee_usd, (float) $referral->discount_applied);
        $this->assertEquals(
            $this->essential->price_monthly_usd,
            (float) $subscription->price_monthly_usd,
            'Free month discount must not change the stored subscription price'
        );
    }

    // ─── Story 4: Full subscribe→referral→activate lifecycle ───

    public function test_subscribe_with_referral_creates_pending_referral_with_zero_reward(): void
    {
        $code = $this->makeReferrer('GIVE20', DiscountType::PERCENTAGE, 20, RewardType::PERCENTAGE, 10);
        $business = $this->makeReferredBusiness();

        $subscription = $this->subscriptionService->subscribe(
            $business->id, $this->essential->id, 'monthly', $code->code
        );

        $referral = Referral::where('subscription_id', $subscription->id)->first();

        $this->assertNotNull($referral);
        $this->assertEquals(ReferralStatus::PENDING, $referral->status);
        $this->assertEquals(0, (float) $referral->reward_amount, 'Reward must be 0 before activation');

        $expectedDiscount = round($this->essential->onboarding_fee_usd * 20 / 100, 2);
        $this->assertEquals($expectedDiscount, (float) $referral->discount_applied);
        $this->assertEquals($this->essential->price_monthly_usd, (float) $subscription->price_monthly_usd);
    }

    // ─── Story 5: Activation calculates reward based on the amount ACTUALLY paid, not full price ───

    public function test_reward_is_based_on_amount_actually_paid_not_full_price(): void
    {
        $code = $this->makeReferrer('REF50', DiscountType::PERCENTAGE, 50, RewardType::PERCENTAGE, 15);
        $business = $this->makeReferredBusiness();

        $subscription = $this->subscriptionService->subscribe(
            $business->id, $this->essential->id, 'monthly', $code->code
        );

        $referral = Referral::where('subscription_id', $subscription->id)->first();

        $expectedDiscount = round($this->essential->onboarding_fee_usd * 50 / 100, 2);
        $this->assertEquals($expectedDiscount, (float) $referral->discount_applied);

        $this->subscriptionService->activateSubscription($subscription);
        $referral->refresh();

        $paidBase = (float) $this->essential->onboarding_fee_usd - (float) $referral->discount_applied;
        $expectedReward = round($paidBase * 15 / 100, 2);

        $this->assertEquals($expectedReward, (float) $referral->reward_amount,
            'reward_amount must be a % of what the referee actually paid (full price minus discount)');
        $this->assertEquals(ReferralStatus::ACTIVE, $referral->status);
        $this->assertNotNull($referral->converted_at);
    }

    // ─── Story 6: Sales rep commission is based on the amount ACTUALLY paid (USD) ───

    public function test_sales_rep_commission_is_based_on_amount_actually_paid(): void
    {
        $salesRepUser = User::factory()->create(['is_active' => true]);
        $code = ReferralCode::create([
            'owner_type' => ReferralCodeOwnerType::SALES_REP,
            'owner_user_id' => $salesRepUser->id,
            'code' => 'SRPCT',
            'discount_type' => DiscountType::FLAT_AMOUNT,
            'discount_value' => 5,
            'reward_type' => RewardType::FLAT_AMOUNT,
            'reward_value' => 2,
            'is_active' => true,
        ]);
        SalesRep::create([
            'user_id' => $salesRepUser->id,
            'referral_code_id' => $code->id,
            'commission_type' => CommissionType::PERCENTAGE,
            'commission_rate' => 10,
            'region' => 'Kampala',
            'is_active' => true,
        ]);

        $business = $this->makeReferredBusiness();
        $subscription = $this->subscriptionService->subscribe(
            $business->id, $this->essential->id, 'monthly', $code->code
        );

        $referral = Referral::where('subscription_id', $subscription->id)->first();

        $this->assertNotNull($referral);
        $this->assertEquals(5, (float) $referral->discount_applied);

        $this->subscriptionService->activateSubscription($subscription);
        $referral->refresh();

        $paidBase = (float) $this->essential->onboarding_fee_usd - (float) $referral->discount_applied;
        $expectedCommission = round($paidBase * 10 / 100, 2);

        $this->assertEquals($expectedCommission, (float) $referral->commission_earned,
            'Commission must be a % of the amount actually paid, not the full price');
    }

    // ─── Story 7: All discount types keep price_monthly_usd at full value ───

    public function test_all_discount_types_leave_subscription_price_unchanged(): void
    {
        $setups = [
            ['code' => 'PCT15', 'type' => DiscountType::PERCENTAGE, 'value' => 15],
            ['code' => 'FLAT25', 'type' => DiscountType::FLAT_AMOUNT, 'value' => 25],
            ['code' => 'FREEM2', 'type' => DiscountType::FREE_MONTH, 'value' => 0],
        ];

        foreach ($setups as $cfg) {
            $code = $this->makeReferrer($cfg['code'], $cfg['type'], $cfg['value']);
            $business = $this->makeReferredBusiness();

            $subscription = $this->subscriptionService->subscribe(
                $business->id, $this->essential->id, 'monthly', $code->code
            );

            $this->assertEquals(
                $this->essential->price_monthly_usd,
                (float) $subscription->price_monthly_usd,
                "price_monthly_usd must stay at {$this->essential->price_monthly_usd} with {$cfg['code']}"
            );
        }
    }

    // ─── Story 8: Grace period is once per subscription lifecycle ───

    public function test_grace_period_is_once_per_subscription(): void
    {
        $business = $this->makeReferredBusiness();
        $subscription = $this->subscriptionService->subscribe(
            $business->id, $this->essential->id, 'monthly'
        );

        $this->subscriptionService->activateSubscription($subscription->fresh());

        $this->subscriptionService->markPastDue($subscription->fresh());
        $this->assertEquals(SubscriptionStatus::PAST_DUE, $subscription->fresh()->status);
        $this->assertNotNull($subscription->fresh()->grace_period_ends_at);
        $this->assertTrue($subscription->fresh()->grace_used);

        // markPastDue guards against non-active, so reset to active first
        $subscription->fresh()->update(['status' => SubscriptionStatus::ACTIVE]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Grace period has already been used');
        $this->subscriptionService->markPastDue($subscription->fresh());
    }
}
