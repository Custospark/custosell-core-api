<?php

namespace Tests\Feature\Api\Billing;

use App\Models\Business;
use App\Models\Plan;
use App\Models\Role;
use App\Models\Subscription;
use App\Models\User;
use Database\Seeders\PlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Guards the yearly→monthly upgrade anomaly, amount-based.
 *
 * The block applies ONLY when a user on an annual plan upgrades to a monthly
 * higher plan whose monthly charge is LOWER than their remaining annual credit
 * (e.g. Personal $100/yr credit → Professional $54/mo). That path yields $0 due
 * while the user still holds more credit than the plan costs - a revenue-loss /
 * chargeback scenario.
 *
 * When the monthly charge EXCEEDS the unused credit (e.g. $20 credit → $35/mo),
 * the upgrade is allowed: the user pays the difference and no money is demanded
 * back from the company.
 */
class YearlyToMonthlyUpgradeBlockTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected Business $business;

    protected string $token;

    protected Plan $essential;

    protected Plan $professional;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(PlanSeeder::class);

        $this->essential = Plan::where('slug', 'essential')->first();
        $this->professional = Plan::where('slug', 'professional')->first();

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

    protected function createYearlySubscription(Plan $plan, Carbon $nextBillingDate): Subscription
    {
        return Subscription::create([
            'business_id' => $this->business->id,
            'plan_id' => $plan->id,
            'status' => 'active',
            'billing_cycle' => 'yearly',
            'starts_at' => now()->subYear(),
            'next_billing_date' => $nextBillingDate,
            'price_monthly_usd' => $plan->price_monthly_usd,
            'price_yearly_usd' => $plan->price_yearly_usd,
            'onboarding_fee_usd' => $plan->onboarding_fee_usd,
        ]);
    }

    public function test_yearly_subscription_with_large_credit_cannot_upgrade_to_monthly(): void
    {
        $subscription = $this->createYearlySubscription(
            $this->essential,
            Carbon::now()->addYear()->startOfDay(),
        );

        $response = $this->withHeaders($this->authHeaders())
            ->postJson("/api/v1/subscriptions/{$subscription->id}/upgrade", [
                'to_plan_id' => $this->professional->id,
                'effective' => 'immediate',
                'billing_cycle' => 'monthly',
            ]);

        $response->assertStatus(422);
        $this->assertStringContainsString('exceeds the monthly upgrade price', $response->json('message'));

        $subscription->refresh();
        $this->assertSame($this->essential->id, (int) $subscription->plan_id);
    }

    public function test_yearly_subscription_with_small_credit_can_upgrade_to_monthly(): void
    {
        // One day left on Essential yearly ($200/yr) → credit ≈ $0.55, well under
        // Professional monthly ($54). The upgrade is allowed and charges the difference.
        $subscription = $this->createYearlySubscription(
            $this->essential,
            Carbon::now()->addDay()->startOfDay(),
        );

        $response = $this->withHeaders($this->authHeaders())
            ->postJson("/api/v1/subscriptions/{$subscription->id}/upgrade", [
                'to_plan_id' => $this->professional->id,
                'effective' => 'immediate',
                'billing_cycle' => 'monthly',
            ]);

        $response->assertStatus(200);
        $proration = $response->json('proration.proration');
        $this->assertLessThan($this->professional->price_monthly_usd, (float) $proration['credit']);
        $this->assertGreaterThan(0, (float) $proration['proration_due']);
        $this->assertSame('monthly', $response->json('proration.billing_cycle'));
    }

    public function test_yearly_subscription_can_upgrade_to_yearly_plan(): void
    {
        $subscription = $this->createYearlySubscription(
            $this->essential,
            Carbon::now()->addYear()->startOfDay(),
        );

        $response = $this->withHeaders($this->authHeaders())
            ->postJson("/api/v1/subscriptions/{$subscription->id}/upgrade", [
                'to_plan_id' => $this->professional->id,
                'effective' => 'immediate',
                'billing_cycle' => 'yearly',
            ]);

        $response->assertStatus(200);
        $this->assertSame('yearly', $response->json('proration.billing_cycle'));
    }

    public function test_yearly_subscription_large_credit_monthly_quote_is_blocked(): void
    {
        $subscription = $this->createYearlySubscription(
            $this->essential,
            Carbon::now()->addYear()->startOfDay(),
        );

        $response = $this->withHeaders($this->authHeaders())
            ->getJson("/api/v1/subscriptions/{$subscription->id}/proration-quote?to_plan_id={$this->professional->id}&billing_cycle=monthly");

        $response->assertStatus(422);
        $this->assertStringContainsString('exceeds the monthly upgrade price', $response->json('message'));
    }

    public function test_yearly_subscription_small_credit_monthly_quote_is_allowed(): void
    {
        $subscription = $this->createYearlySubscription(
            $this->essential,
            Carbon::now()->addDay()->startOfDay(),
        );

        $response = $this->withHeaders($this->authHeaders())
            ->getJson("/api/v1/subscriptions/{$subscription->id}/proration-quote?to_plan_id={$this->professional->id}&billing_cycle=monthly");

        $response->assertStatus(200);
        $this->assertSame('monthly', $response->json('data.billing_cycle'));
    }

    public function test_monthly_subscription_can_still_upgrade_to_monthly_plan(): void
    {
        $subscription = Subscription::create([
            'business_id' => $this->business->id,
            'plan_id' => $this->essential->id,
            'status' => 'active',
            'billing_cycle' => 'monthly',
            'starts_at' => now()->subMonth(),
            'next_billing_date' => Carbon::now()->addMonth()->startOfDay(),
            'price_monthly_usd' => $this->essential->price_monthly_usd,
            'price_yearly_usd' => $this->essential->price_yearly_usd,
        ]);

        $response = $this->withHeaders($this->authHeaders())
            ->postJson("/api/v1/subscriptions/{$subscription->id}/upgrade", [
                'to_plan_id' => $this->professional->id,
                'effective' => 'immediate',
                'billing_cycle' => 'monthly',
            ]);

        $response->assertStatus(200);
        $this->assertSame('monthly', $response->json('proration.billing_cycle'));
    }
}
