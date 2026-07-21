<?php

namespace Tests\Unit\Billing;

use App\Enums\Billing\DiscountType;
use App\Enums\Billing\ReferralCodeOwnerType;
use App\Models\Business;
use App\Models\ReferralCode;
use App\Models\User;
use App\Services\ReferralCodeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReferralCodeTest extends TestCase
{
    use RefreshDatabase;

    protected ReferralCodeService $service;

    protected Business $business;

    protected function setUp(): void
    {
        parent::setUp();

        $user = User::factory()->create(['is_active' => true]);
        $this->business = Business::factory()->create(['owner_id' => $user->id]);

        $this->service = app(ReferralCodeService::class);
    }

    public function test_generates_unique_code(): void
    {
        $code1 = $this->service->create([
            'owner_type' => ReferralCodeOwnerType::BUSINESS,
            'owner_business_id' => $this->business->id,
            'discount_type' => DiscountType::PERCENTAGE,
            'discount_value' => 10,
        ]);

        $code2 = $this->service->create([
            'owner_type' => ReferralCodeOwnerType::BUSINESS,
            'owner_business_id' => $this->business->id,
            'discount_type' => DiscountType::PERCENTAGE,
            'discount_value' => 10,
        ]);

        $this->assertNotEmpty($code1->code);
        $this->assertNotEmpty($code2->code);
        $this->assertNotEquals($code1->code, $code2->code);
    }

    public function test_is_valid_returns_true_for_active_code(): void
    {
        $referralCode = ReferralCode::create([
            'owner_type' => ReferralCodeOwnerType::BUSINESS,
            'owner_business_id' => $this->business->id,
            'code' => 'VALID10',
            'discount_type' => DiscountType::PERCENTAGE,
            'discount_value' => 10,
            'is_active' => true,
            'max_uses' => null,
            'expires_at' => null,
        ]);

        $this->assertTrue($referralCode->isValid());
        $this->assertFalse($referralCode->isExpired());
    }

    public function test_is_valid_returns_false_when_expired(): void
    {
        $referralCode = ReferralCode::create([
            'owner_type' => ReferralCodeOwnerType::BUSINESS,
            'owner_business_id' => $this->business->id,
            'code' => 'EXPIRED',
            'discount_type' => DiscountType::PERCENTAGE,
            'discount_value' => 10,
            'is_active' => true,
            'expires_at' => now()->subDay(),
            'max_uses' => null,
        ]);

        $this->assertFalse($referralCode->isValid());
        $this->assertTrue($referralCode->isExpired());
    }

    public function test_is_valid_returns_false_when_max_uses_reached(): void
    {
        $referralCode = ReferralCode::create([
            'owner_type' => ReferralCodeOwnerType::BUSINESS,
            'owner_business_id' => $this->business->id,
            'code' => 'MAXED',
            'discount_type' => DiscountType::PERCENTAGE,
            'discount_value' => 10,
            'is_active' => true,
            'max_uses' => 1,
            'used_count' => 1,
            'expires_at' => null,
        ]);

        $this->assertFalse($referralCode->isValid());
    }

    public function test_mark_used_increments_count(): void
    {
        $referralCode = ReferralCode::create([
            'owner_type' => ReferralCodeOwnerType::BUSINESS,
            'owner_business_id' => $this->business->id,
            'code' => 'INCR',
            'discount_type' => DiscountType::PERCENTAGE,
            'discount_value' => 10,
            'is_active' => true,
            'max_uses' => 10,
            'used_count' => 0,
            'expires_at' => null,
        ]);

        $referralCode->markUsed();

        $this->assertEquals(1, $referralCode->fresh()->used_count);
    }
}
