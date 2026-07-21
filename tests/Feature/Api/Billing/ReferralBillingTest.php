<?php

namespace Tests\Feature\Api\Billing;

use App\Enums\Billing\DiscountType;
use App\Enums\Billing\ReferralCodeOwnerType;
use App\Models\Business;
use App\Models\Plan;
use App\Models\ReferralCode;
use App\Models\Role;
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
            ->assertJsonPath('business_id', $this->business->id)
            ->assertJsonPath('plan_id', $this->essentialPlan->id);

        $subscriptionId = $response->json('id');

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
            ->assertJsonPath('business_id', $this->business->id)
            ->assertJsonPath('plan_id', $this->essentialPlan->id);

        $subscriptionId = $response->json('id');

        $this->assertDatabaseHas('subscriptions', [
            'id' => $subscriptionId,
            'business_id' => $this->business->id,
            'plan_id' => $this->essentialPlan->id,
        ]);

        $this->assertDatabaseMissing('referrals', [
            'subscription_id' => $subscriptionId,
        ]);
    }
}
