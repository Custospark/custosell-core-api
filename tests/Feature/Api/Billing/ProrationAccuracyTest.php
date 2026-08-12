<?php

namespace Tests\Feature\Api\Billing;

use App\Models\Business;
use App\Models\Plan;
use App\Models\Role;
use App\Models\Subscription;
use App\Models\User;
use App\Services\Currency\Contracts\CurrencyExchangeServiceInterface;
use Database\Seeders\PlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Controller-level accuracy checks for every subscription/payment scenario.
 *
 * Real plan prices (USD):
 *   Professional $54/mo · $540/yr · onboarding $95
 *   Enterprise   $135/mo · $1350/yr · onboarding $200
 *   Essential    $20/mo · $200/yr · onboarding $40
 *
 * Fixed test rate: 1 USD = 3708.59 UGX (matches the observed frontend display rate).
 *
 * Business is UGX → PesaPal supported → all gateway charges are converted to UGX.
 *
 * Contract under test (per Payment Architecture ADR):
 *   - subscription/renewal/onboarding: amount is authoritative server-side (USD plan price),
 *     validated in USD, then converted to local for the gateway.
 *   - upgrade_proration/billing_cycle_change: frontend sends the USD proration amount;
 *     backend validates it against the stored pending USD amount, then converts to local.
 */
class ProrationAccuracyTest extends TestCase
{
    use RefreshDatabase;

    private const RATE = 3708.59;

    protected User $user;

    protected Business $business;

    protected string $token;

    protected Plan $essential;

    protected Plan $professional;

    protected Plan $enterprise;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(PlanSeeder::class);

        $this->essential = Plan::where('slug', 'essential')->first();
        $this->professional = Plan::where('slug', 'professional')->first();
        $this->enterprise = Plan::where('slug', 'enterprise')->first();

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

        $this->mock(CurrencyExchangeServiceInterface::class, function ($mock) {
            $mock->shouldReceive('getExchangeRate')
                ->with('USD', 'UGX')
                ->andReturn(self::RATE);
        });
    }

    protected function authHeaders(): array
    {
        return ['Authorization' => "Bearer {$this->token}"];
    }

    protected function createSubscription(Plan $plan, Carbon $nextBillingDate, string $status = 'active', string $cycle = 'monthly'): Subscription
    {
        return Subscription::create([
            'business_id' => $this->business->id,
            'plan_id' => $plan->id,
            'status' => $status,
            'billing_cycle' => $cycle,
            'starts_at' => now()->subMonth(),
            'next_billing_date' => $nextBillingDate,
            'price_monthly_usd' => $plan->price_monthly_usd,
            'price_yearly_usd' => $plan->price_yearly_usd,
            'onboarding_fee_usd' => $plan->onboarding_fee_usd,
        ]);
    }

    protected function expectedProration(Plan $current, Plan $target, Carbon $nextBillingDate, string $cycle = 'monthly'): array
    {
        $now = Carbon::now()->startOfDay();
        $periodEnd = $nextBillingDate->copy()->startOfDay();
        $periodStart = $cycle === 'yearly'
            ? $periodEnd->copy()->subYear()->startOfDay()
            : $periodEnd->copy()->subMonth()->startOfDay();

        $daysInPeriod = max(1, (int) $periodStart->diffInDays($periodEnd));
        $daysRemaining = $periodEnd->lte($now) ? 0 : (int) $now->diffInDays($periodEnd);

        $oldPrice = $cycle === 'yearly'
            ? (float) $current->price_yearly_usd
            : (float) $current->price_monthly_usd;
        $newPrice = $cycle === 'yearly'
            ? (float) $target->price_yearly_usd
            : (float) $target->price_monthly_usd;

        // Full-window proration: keep the user's paid-through date, charge the
        // DIFFERENCE over ALL remaining days (top-ups can push daysRemaining
        // several periods beyond the single-period daysInPeriod figure).
        $credit = round($oldPrice * ($daysRemaining / $daysInPeriod), 2);
        $charge = round($newPrice * ($daysRemaining / $daysInPeriod), 2);
        $due = round(max(0, $charge - $credit), 2);

        return [
            'days_in_period' => $daysInPeriod,
            'days_remaining' => $daysRemaining,
            'credit' => $credit,
            'charge' => $charge,
            'due' => $due,
        ];
    }

    // ─── QUOTE (trial Professional → Enterprise) ─────────────────────────

    public function test_proration_quote_endpoint_returns_exact_figures_for_trial_upgrade(): void
    {
        $nextBillingDate = Carbon::now()->addDays(20)->startOfDay();
        $subscription = $this->createSubscription($this->professional, $nextBillingDate, 'trial');

        $response = $this->withHeaders($this->authHeaders())
            ->getJson("/api/v1/subscriptions/{$subscription->id}/proration-quote?to_plan_id={$this->enterprise->id}&billing_cycle=monthly");

        $response->assertStatus(200);

        $proration = $response->json('data.proration');

        // Trial = paid nothing → no unused credit → full target plan price, credit 0.
        $this->assertSame(135.0, (float) $proration['new_price']);
        $this->assertSame(135.0, (float) $proration['charge']);
        $this->assertSame(0.0, (float) $proration['credit']);
        $this->assertSame(135.0, (float) $proration['proration_due']);
        $this->assertSame(135.0, (float) $proration['proration_due_usd']);
    }

    // ─── UPGRADE (stores pending amount, defers plan change) ──────────────

    public function test_upgrade_endpoint_stores_authoritative_pending_amount_in_metadata(): void
    {
        $nextBillingDate = Carbon::now()->addDays(20)->startOfDay();
        $subscription = $this->createSubscription($this->professional, $nextBillingDate);
        $expected = $this->expectedProration($this->professional, $this->enterprise, $nextBillingDate);

        $response = $this->withHeaders($this->authHeaders())
            ->postJson("/api/v1/subscriptions/{$subscription->id}/upgrade", [
                'to_plan_id' => $this->enterprise->id,
                'effective' => 'immediate',
                'billing_cycle' => 'monthly',
            ]);

        $response->assertStatus(200);
        $this->assertSame($expected['due'], (float) $response->json('proration.proration.proration_due'));
        $this->assertSame($expected['due'], (float) $response->json('proration.proration.proration_due_usd'));

        $subscription->refresh();
        $metadata = $subscription->metadata ?? [];
        $this->assertSame($expected['due'], (float) ($metadata['pending_upgrade_amount_usd'] ?? 0));
        $this->assertSame($this->enterprise->id, (int) ($metadata['pending_upgrade_to_plan_id'] ?? 0));
        $this->assertSame('monthly', $metadata['pending_upgrade_billing_cycle'] ?? null);

        // Plan must NOT change until payment completes
        $this->assertSame($this->professional->id, (int) $subscription->plan_id);
    }

    // ─── TOPPED-UP WINDOW (multi-month prepaid coverage) ──────────────────

    public function test_upgrade_after_topup_charges_full_window_difference(): void
    {
        // A 3-month top-up pushes next_billing_date ~122 days out while the billing
        // window stays one month → daysRemaining (122) >> daysInPeriod (31). The
        // quote must charge the DIFFERENCE over ALL remaining days, never just one
        // new-plan period, so the topped-up coverage is never discounted/overwritten.
        $nextBillingDate = Carbon::now()->addDays(122)->startOfDay();
        $subscription = $this->createSubscription($this->professional, $nextBillingDate, 'active', 'monthly');

        $response = $this->withHeaders($this->authHeaders())
            ->postJson("/api/v1/subscriptions/{$subscription->id}/upgrade", [
                'to_plan_id' => $this->enterprise->id,
                'effective' => 'immediate',
                'billing_cycle' => 'monthly',
            ]);

        $response->assertStatus(200);

        $proration = $response->json('proration.proration');
        $expected = $this->expectedProration($this->professional, $this->enterprise, $nextBillingDate);

        $this->assertGreaterThan(31, (int) $proration['days_remaining']);
        $this->assertSame($expected['credit'], (float) $proration['credit']);
        $this->assertSame($expected['charge'], (float) $proration['charge']);
        $this->assertSame($expected['due'], (float) $proration['proration_due']);
        $this->assertSame($expected['due'], (float) $proration['proration_due_usd']);

        // Plan must NOT change until payment completes
        $subscription->refresh();
        $this->assertSame($this->professional->id, (int) $subscription->plan_id);
    }

    // ─── LIVE PLAN PRICING (stale subscription snapshot must be ignored) ──

    public function test_upgrade_quote_uses_live_plan_price_not_stale_subscription_snapshot(): void
    {
        $nextBillingDate = Carbon::now()->addDays(30)->startOfDay();

        // Subscription was created when Professional cost $54/mo — its snapshot
        // columns still hold the OLD price (36,908.70 UGX in the field case).
        $this->professional->update([
            'price_monthly_usd' => 0.30,
            'price_yearly_usd' => 3.00,
        ]);

        $subscription = Subscription::create([
            'business_id' => $this->business->id,
            'plan_id' => $this->professional->id,
            'status' => 'active',
            'billing_cycle' => 'monthly',
            'starts_at' => now()->subMonth(),
            'next_billing_date' => $nextBillingDate,
            'price_monthly_usd' => 54.0,
            'price_yearly_usd' => 540.0,
            'onboarding_fee_usd' => 95.0,
        ]);

        $response = $this->withHeaders($this->authHeaders())
            ->postJson("/api/v1/subscriptions/{$subscription->id}/upgrade", [
                'to_plan_id' => $this->enterprise->id,
                'effective' => 'immediate',
                'billing_cycle' => 'monthly',
            ]);

        $response->assertStatus(200);

        $proration = $response->json('proration.proration');
        $expected = $this->expectedProration($this->professional, $this->enterprise, $nextBillingDate);

        // Old price + credit must reflect the LIVE plan price ($0.30), never the
        // stale $54 snapshot. Enterprise ($135/mo) full-window charge applied.
        $this->assertSame(0.30, (float) $proration['old_price']);
        $this->assertSame($expected['credit'], (float) $proration['credit']);
        $this->assertSame($expected['charge'], (float) $proration['charge']);
        $this->assertSame($expected['due'], (float) $proration['proration_due']);
        $this->assertSame($expected['due'], (float) $proration['proration_due_usd']);
    }

    // ─── BILLING CYCLE CHANGE (monthly → yearly) ──────────────────────────

    public function test_billing_cycle_change_monthly_to_yearly_stores_pending_and_quotes(): void
    {
        $nextBillingDate = Carbon::now()->addDays(20)->startOfDay();
        $subscription = $this->createSubscription($this->professional, $nextBillingDate, 'active', 'monthly');

        // Active monthly → yearly: prepay full year ($540), offset by unused monthly credit.
        $credit = $this->expectedProration($this->professional, $this->professional, $nextBillingDate, 'monthly')['credit'];
        $expectedDue = round(max(0, 540.0 - $credit), 2);

        $response = $this->withHeaders($this->authHeaders())
            ->postJson("/api/v1/subscriptions/{$subscription->id}/billing-cycle", [
                'billing_cycle' => 'yearly',
            ]);

        $response->assertStatus(200)
            ->assertJsonPath('payment_required', true);

        $proration = $response->json('proration.proration');
        $this->assertSame($expectedDue, (float) $proration['proration_due_usd']);
        $this->assertSame(540.0, (float) $proration['new_price']);
        $this->assertSame($credit, (float) $proration['credit']);

        $subscription->refresh();
        $metadata = $subscription->metadata ?? [];
        $this->assertSame($expectedDue, (float) ($metadata['pending_cycle_change_amount_usd'] ?? 0));
        $this->assertSame('yearly', $metadata['pending_billing_cycle'] ?? null);

        // Plan and cycle unchanged until payment confirms
        $this->assertSame($this->professional->id, (int) $subscription->plan_id);
        $this->assertSame('monthly', $subscription->billing_cycle);
    }

    // ─── ZERO-COST UPGRADE (due ≤ 0) ──────────────────────────────────────

    public function test_zero_cost_upgrade_creates_zero_payment_and_changes_plan(): void
    {
        $subscription = $this->createSubscription(
            $this->professional,
            Carbon::now()->addDays(20)->startOfDay(),
            'active',
            'monthly',
        );

        // Upgrade Professional → Essential (lower price) → proration due = $0 → zero-cost path.
        $response = $this->withHeaders($this->authHeaders())
            ->postJson("/api/v1/subscriptions/{$subscription->id}/upgrade", [
                'to_plan_id' => $this->essential->id,
                'effective' => 'immediate',
                'billing_cycle' => 'monthly',
            ]);

        $response->assertStatus(200);
        $this->assertSame(0.0, (float) $response->json('proration.proration.proration_due'));

        $subscription->refresh();
        $this->assertSame($this->essential->id, (int) $subscription->plan_id);

        $this->assertDatabaseHas('billing_payments', [
            'business_id' => $this->business->id,
            'subscription_id' => $subscription->id,
            'amount' => 0.0,
            'currency' => 'USD',
            'payment_type' => 'upgrade_proration',
            'status' => 'completed',
        ]);
    }

    // ─── DOWNGRADE (no payment) ───────────────────────────────────────────

    public function test_downgrade_immediate_requires_no_payment(): void
    {
        $subscription = $this->createSubscription(
            $this->enterprise,
            Carbon::now()->addDays(20)->startOfDay(),
            'active',
            'monthly',
        );

        $response = $this->withHeaders($this->authHeaders())
            ->postJson("/api/v1/subscriptions/{$subscription->id}/downgrade", [
                'to_plan_id' => $this->professional->id,
                'effective' => 'immediate',
            ]);

        $response->assertStatus(200);
        $subscription->refresh();
        $this->assertSame($this->professional->id, (int) $subscription->plan_id);

        $this->assertDatabaseMissing('billing_payments', [
            'business_id' => $this->business->id,
            'subscription_id' => $subscription->id,
        ]);
    }

    public function test_downgrade_end_of_period_schedules_without_payment(): void
    {
        $subscription = $this->createSubscription(
            $this->enterprise,
            Carbon::now()->addDays(20)->startOfDay(),
            'active',
            'monthly',
        );

        $response = $this->withHeaders($this->authHeaders())
            ->postJson("/api/v1/subscriptions/{$subscription->id}/downgrade", [
                'to_plan_id' => $this->professional->id,
                'effective' => 'end_of_period',
            ]);

        $response->assertStatus(200);
        $this->assertSame($this->professional->id, (int) $response->json('scheduled_change.to_plan_id'));

        $subscription->refresh();
        $this->assertSame($this->enterprise->id, (int) $subscription->plan_id);

        $this->assertDatabaseMissing('billing_payments', [
            'business_id' => $this->business->id,
            'subscription_id' => $subscription->id,
        ]);
    }
}
