<?php

namespace Tests\Unit\Billing;

use App\Enums\Billing\DiscountType;
use App\Enums\Billing\ReferralCodeOwnerType;
use App\Enums\Billing\SubscriptionStatus;
use App\Models\Business;
use App\Models\Plan;
use App\Models\ReferralCode;
use App\Models\Subscription;
use App\Models\User;
use App\Services\ReferralService;
use Database\Seeders\PlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReferralTest extends TestCase
{
    use RefreshDatabase;

    protected ReferralService $service;

    protected Business $business;

    protected Business $referringBusiness;

    protected Subscription $subscription;

    protected ReferralCode $referralCode;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(PlanSeeder::class);

        $user = User::factory()->create(['is_active' => true]);
        $this->business = Business::factory()->create(['owner_id' => $user->id]);
        $this->referringBusiness = Business::factory()->create(['owner_id' => $user->id]);

        $plan = Plan::where('slug', 'essential')->first();

        $this->subscription = Subscription::create([
            'business_id' => $this->business->id,
            'plan_id' => $plan->id,
            'status' => SubscriptionStatus::ACTIVE,
            'billing_cycle' => 'monthly',
            'starts_at' => now(),
            'next_billing_date' => now()->addMonth(),
        ]);

        $this->referralCode = ReferralCode::create([
            'owner_type' => ReferralCodeOwnerType::BUSINESS,
            'owner_business_id' => $this->referringBusiness->id,
            'code' => 'REFCODE',
            'discount_type' => DiscountType::PERCENTAGE,
            'discount_value' => 10,
            'is_active' => true,
            'max_uses' => 10,
            'used_count' => 0,
            'expires_at' => null,
        ]);

        $this->service = app(ReferralService::class);
    }

    public function test_process_referral_creates_referral(): void
    {
        $referral = $this->service->processReferral(
            $this->referralCode->code,
            $this->subscription->id,
            $this->business->id
        );

        $this->assertDatabaseHas('referrals', [
            'id' => $referral->id,
            'referral_code_id' => $this->referralCode->id,
            'subscription_id' => $this->subscription->id,
            'referred_business_id' => $this->business->id,
            'status' => 'pending',
        ]);

        // Applying/previewing a code must NOT consume it - usage only counts
        // once a payment has actually been claimed with the code.
        $this->assertEquals(0, $this->referralCode->fresh()->used_count);
    }

    public function test_code_counts_as_used_only_after_payment_claimed(): void
    {
        $referral = $this->service->processReferral(
            $this->referralCode->code,
            $this->subscription->id,
            $this->business->id
        );

        $this->assertEquals(0, $this->referralCode->fresh()->used_count, 'preview does not consume the code');

        $this->service->markActive($referral->id);

        $this->assertEquals(1, $this->referralCode->fresh()->used_count, 'claimed payment counts the code as used');
    }

    public function test_previewing_then_applying_other_code_swaps_pending_referral(): void
    {
        // A PENDING (unpaid) code is only a preview - a different code applied
        // before any payment is claimed replaces it (latest code wins).
        $first = $this->service->processReferral(
            $this->referralCode->code,
            $this->subscription->id,
            $this->business->id
        );

        $otherCode = ReferralCode::create([
            'owner_type' => ReferralCodeOwnerType::BUSINESS,
            'owner_business_id' => $this->referringBusiness->id,
            'code' => 'OTHER09',
            'discount_type' => DiscountType::FLAT_AMOUNT,
            'discount_value' => 15,
            'is_active' => true,
            'max_uses' => 10,
            'used_count' => 0,
            'expires_at' => null,
        ]);

        $second = $this->service->processReferral(
            $otherCode->code,
            $this->subscription->id,
            $this->business->id
        );

        $this->assertSame($first->id, $second->id, 'same referral row is re-pointed, no stacking');
        $this->assertSame($otherCode->id, $second->fresh()->referral_code_id, 'newest code wins while unpaid');
        $this->assertSame('15.00', (string) $second->fresh()->discount_applied);
        $this->assertDatabaseCount('referrals', 1);
    }

    public function test_account_stays_locked_after_code_is_claimed(): void
    {
        $first = $this->service->processReferral(
            $this->referralCode->code,
            $this->subscription->id,
            $this->business->id
        );
        $this->service->markActive($first->id);

        $otherCode = ReferralCode::create([
            'owner_type' => ReferralCodeOwnerType::BUSINESS,
            'owner_business_id' => $this->referringBusiness->id,
            'code' => 'LOCKED01',
            'discount_type' => DiscountType::PERCENTAGE,
            'discount_value' => 10,
            'is_active' => true,
            'max_uses' => 10,
            'used_count' => 0,
            'expires_at' => null,
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('This account has already used a referral code');

        $this->service->processReferral(
            $otherCode->code,
            $this->subscription->id,
            $this->business->id
        );
    }

    public function test_process_referral_throws_for_invalid_code(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Referral code is invalid or expired');

        $this->service->processReferral(
            'INVALID',
            $this->subscription->id,
            $this->business->id
        );
    }

    public function test_reapplying_same_code_while_pending_is_idempotent(): void
    {
        $first = $this->service->processReferral(
            $this->referralCode->code,
            $this->subscription->id,
            $this->business->id
        );

        // Still unpaid → re-applying the same code refreshes the pending
        // referral instead of raising a duplicate error.
        $second = $this->service->processReferral(
            $this->referralCode->code,
            $this->subscription->id,
            $this->business->id
        );

        $this->assertSame($first->id, $second->id, 'no new referral row on repeat apply');
        $this->assertDatabaseCount('referrals', 1);
        $this->assertEquals(0, $this->referralCode->fresh()->used_count, 'preview never consumes the code');
    }

    public function test_mark_active_sets_status(): void
    {
        $referral = $this->service->processReferral(
            $this->referralCode->code,
            $this->subscription->id,
            $this->business->id
        );

        $activated = $this->service->markActive($referral->id);

        $this->assertEquals('active', $activated->status->value);
        $this->assertNotNull($activated->converted_at);
    }

    public function test_mark_rewarded_sets_rewarded(): void
    {
        $referral = $this->service->processReferral(
            $this->referralCode->code,
            $this->subscription->id,
            $this->business->id
        );

        $rewarded = $this->service->markRewarded($referral->id);

        $this->assertEquals('rewarded', $rewarded->status->value);
        $this->assertTrue($rewarded->reward_paid);
    }
}
