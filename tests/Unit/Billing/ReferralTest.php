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

        $this->assertEquals(1, $this->referralCode->fresh()->used_count);
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

    public function test_process_referral_throws_for_duplicate(): void
    {
        $this->service->processReferral(
            $this->referralCode->code,
            $this->subscription->id,
            $this->business->id
        );

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('This business has already used this referral code');

        $this->service->processReferral(
            $this->referralCode->code,
            $this->subscription->id,
            $this->business->id
        );
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
