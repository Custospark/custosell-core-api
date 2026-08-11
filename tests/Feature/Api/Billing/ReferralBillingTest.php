<?php

namespace Tests\Feature\Api\Billing;

use App\Enums\Billing\CommissionType;
use App\Enums\Billing\DiscountType;
use App\Enums\Billing\ReferralCodeOwnerType;
use App\Models\Business;
use App\Models\Plan;
use App\Models\ReferralCode;
use App\Models\Role;
use App\Models\SalesRep;
use App\Models\User;
use Database\Seeders\PlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReferralBillingTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected Business $business;

    protected string $token;

    protected Plan $essentialPlan;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(PlanSeeder::class);

        $this->essentialPlan = Plan::where('slug', 'essential')->first();

        $this->user = User::factory()->create(['is_active' => true]);
        $this->token = $this->user->createToken('test')->plainTextToken;

        $this->business = Business::factory()->create([
            'owner_id' => $this->user->id,
            'currency' => 'UGX',
            'status' => 'active',
        ]);
        $this->user->business_id = $this->business->id;
        $this->user->save();

        $adminRole = Role::create([
            'business_id' => $this->business->id,
            'name' => 'Admin',
            'slug' => 'admin',
            'permissions' => ['subscriptions' => true],
        ]);
        $this->user->role_id = $adminRole->id;
        $this->user->save();
    }

    protected function authHeaders(): array
    {
        return ['Authorization' => "Bearer {$this->token}"];
    }

    public function test_subscribe_with_referral_code_creates_subscription_and_referral(): void
    {
        ReferralCode::create([
            'owner_type' => ReferralCodeOwnerType::BUSINESS,
            'owner_business_id' => $this->business->id,
            'code' => 'SUMMER20',
            'discount_type' => DiscountType::PERCENTAGE,
            'discount_value' => 20,
            'is_active' => true,
            'max_uses' => 100,
            'used_count' => 0,
        ]);

        $response = $this->withHeaders($this->authHeaders())
            ->postJson('/api/v1/subscriptions/subscribe', [
                'plan_id' => $this->essentialPlan->id,
                'billing_cycle' => 'monthly',
                'referral_code' => 'SUMMER20',
            ]);

        $response->assertStatus(201)
            ->assertJsonPath('data.business_id', $this->business->id)
            ->assertJsonPath('data.plan_id', $this->essentialPlan->id);

        $subscriptionId = $response->json('data.id');

        $this->assertDatabaseHas('subscriptions', [
            'id' => $subscriptionId,
            'business_id' => $this->business->id,
            'plan_id' => $this->essentialPlan->id,
        ]);

        $this->assertDatabaseHas('referrals', [
            'subscription_id' => $subscriptionId,
            'referred_business_id' => $this->business->id,
            'status' => 'pending',
        ]);
    }

    public function test_subscribe_without_referral_code_creates_subscription_only(): void
    {
        $response = $this->withHeaders($this->authHeaders())
            ->postJson('/api/v1/subscriptions/subscribe', [
                'plan_id' => $this->essentialPlan->id,
                'billing_cycle' => 'monthly',
            ]);

        $response->assertStatus(201)
            ->assertJsonPath('data.business_id', $this->business->id)
            ->assertJsonPath('data.plan_id', $this->essentialPlan->id);

        $subscriptionId = $response->json('data.id');

        $this->assertDatabaseHas('subscriptions', [
            'id' => $subscriptionId,
            'business_id' => $this->business->id,
            'plan_id' => $this->essentialPlan->id,
        ]);

        $this->assertDatabaseMissing('referrals', [
            'subscription_id' => $subscriptionId,
        ]);
    }

    public function test_sales_rep_payout_history_includes_rep_level_payouts(): void
    {
        $repCode = ReferralCode::create([
            'owner_type' => ReferralCodeOwnerType::SALES_REP,
            'owner_user_id' => $this->user->id,
            'code' => 'REP10',
            'discount_type' => DiscountType::PERCENTAGE,
            'discount_value' => 10,
            'is_active' => true,
        ]);

        $salesRep = SalesRep::create([
            'user_id' => $this->user->id,
            'referral_code_id' => $repCode->id,
            'commission_rate' => 30,
            'commission_type' => CommissionType::PERCENTAGE,
            'is_active' => true,
        ]);

        $salesRep->payouts()->create([
            'amount' => 9.65,
            'currency' => 'USD',
            'status' => 'paid',
            'paid_at' => now(),
        ]);

        $response = $this->withHeaders($this->authHeaders())
            ->getJson('/api/v1/payouts/my-history');

        $response->assertStatus(200)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.amount', '9.65')
            ->assertJsonPath('data.0.status', 'paid');
    }
}
