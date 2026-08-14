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
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

/**
 * Contract checks for the /billing/payments/initiate endpoint.
 *
 * Real plan prices (USD):
 *   Professional $54/mo · $540/yr · onboarding $95
 *   Enterprise   $135/mo · $1350/yr · onboarding $200
 *   Essential    $20/mo · $200/yr · onboarding $40
 *
 * Fixed test rate: 1 USD = 3708.59 UGX (matches the observed frontend display rate).
 *
 * Contract under test (per Payment Architecture ADR):
 *   - subscription/renewal/onboarding: amount is authoritative server-side (USD plan price),
 *     validated in USD, then converted to local for the gateway.
 *   - upgrade_proration/billing_cycle_change: frontend sends the USD proration amount;
 *     backend validates it against the stored pending USD amount, then converts to local.
 *   - a tampered amount is rejected (502) with no payment record created.
 *   - an approved upgrade payment preserves the topped-up paid-through date and
 *     clears the pending-upgrade metadata.
 */
class PaymentInitiateAccuracyTest extends TestCase
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

    protected function usdToLocal(float $usd): float
    {
        return round($usd * self::RATE, 2);
    }

    protected function mockPesapal(): void
    {
        Config::set('pesapal.enabled', true);

        $mockTxnId = 'mock-txn-' . uniqid();

        $this->mock(\App\Services\Payment\Gateways\PesaPalGateway::class, function ($mock) use ($mockTxnId) {
            $mock->shouldReceive('isEnabled')->andReturn(true);
            $mock->shouldReceive('getSupportedCurrencies')->andReturn(['UGX', 'KES', 'TZS', 'USD']);
            $mock->shouldReceive('initiate')->andReturn([
                'gateway_txn_id' => $mockTxnId,
                'gateway_ref' => 'mock-ref-' . uniqid(),
                'type' => 'redirect',
                'redirect_url' => 'https://pay.pesapal.com/mock',
                'message' => 'Success',
                'raw_response' => [],
            ]);
            $mock->shouldReceive('verify')->with($mockTxnId)->andReturn([
                'success' => true,
                'status' => 'successful',
                'transaction_id' => $mockTxnId,
                'message' => 'Verified',
            ]);
        });
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

    // ─── VALIDATION ────────────────────────────────────────────────────────

    public function test_initiate_payment_returns_422_for_missing_fields(): void
    {
        $this->createSubscription(
            $this->essential,
            Carbon::now()->addMonth()->startOfDay(),
        );

        $this->withHeaders($this->authHeaders())
            ->postJson('/api/v1/billing/payments/initiate', [])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['gateway_name', 'amount', 'currency', 'payment_type']);
    }

    // ─── SUBSCRIBE / ACTIVATE (authoritative plan price) ──────────────────

    public function test_subscription_payment_amount_is_authoritative_plan_price_during_trial(): void
    {
        $this->mockPesapal();

        $subscription = $this->createSubscription(
            $this->professional,
            Carbon::now()->addMonth()->startOfDay(),
            'trial',
        );

        // Send a deliberately wrong amount - backend must recompute to the plan price,
        // validate in USD, then convert to UGX: 54 × 3708.59 = 200,263.86.
        $response = $this->withHeaders($this->authHeaders())
            ->postJson('/api/v1/billing/payments/initiate', [
                'gateway_name' => 'pesapal',
                'phone' => '0771234567',
                'amount' => 1.00,
                'currency' => 'USD',
                'payment_type' => 'subscription',
            ]);

        $response->assertStatus(201);

        $this->assertDatabaseHas('billing_payments', [
            'business_id' => $this->business->id,
            'subscription_id' => $subscription->id,
            'amount' => $this->usdToLocal((float) $this->professional->price_monthly_usd),
            'currency' => 'UGX',
            'payment_type' => 'subscription',
            'status' => 'pending',
        ]);
    }

    public function test_initiate_payment_with_valid_data_creates_pending_payment(): void
    {
        $this->mockPesapal();

        $this->createSubscription(
            $this->essential,
            Carbon::now()->addMonth()->startOfDay(),
        );

        // Sent amount ignored - backend recomputes authoritative $20/mo → 20 × 3708.59 = 74,171.80 UGX.
        $response = $this->withHeaders($this->authHeaders())
            ->postJson('/api/v1/billing/payments/initiate', [
                'gateway_name' => 'pesapal',
                'phone' => '0771234567',
                'amount' => 75000,
                'currency' => 'UGX',
                'payment_type' => 'subscription',
            ]);

        $response->assertStatus(201);

        $this->assertDatabaseHas('billing_payments', [
            'business_id' => $this->business->id,
            'amount' => $this->usdToLocal((float) $this->essential->price_monthly_usd),
            'currency' => 'UGX',
            'status' => 'pending',
            'gateway_name' => 'pesapal',
        ]);
    }

    public function test_initiate_payment_returns_error_for_invalid_gateway(): void
    {
        $this->createSubscription(
            $this->essential,
            Carbon::now()->addMonth()->startOfDay(),
        );

        $response = $this->withHeaders($this->authHeaders())
            ->postJson('/api/v1/billing/payments/initiate', [
                'gateway_name' => 'nonexistent_gateway',
                'phone' => '0771234567',
                'amount' => 75000,
                'currency' => 'UGX',
                'payment_type' => 'subscription',
            ]);

        $response->assertStatus(502)
            ->assertJsonStructure(['message']);
    }

    // ─── ONBOARDING (authoritative onboarding fee) ────────────────────────

    public function test_onboarding_payment_amount_is_authoritative(): void
    {
        $this->mockPesapal();

        $subscription = $this->createSubscription(
            $this->professional,
            Carbon::now()->addMonth()->startOfDay(),
            'trial',
        );

        // Frontend amount ignored; authoritative = $95 onboarding fee → 352,316.05 UGX.
        $response = $this->withHeaders($this->authHeaders())
            ->postJson('/api/v1/billing/payments/initiate', [
                'gateway_name' => 'pesapal',
                'phone' => '0771234567',
                'amount' => 5.00,
                'currency' => 'USD',
                'payment_type' => 'onboarding',
            ]);

        $response->assertStatus(201);

        $this->assertDatabaseHas('billing_payments', [
            'business_id' => $this->business->id,
            'subscription_id' => $subscription->id,
            'amount' => $this->usdToLocal((float) $this->professional->onboarding_fee_usd),
            'currency' => 'UGX',
            'payment_type' => 'onboarding',
            'status' => 'pending',
        ]);
    }

    // ─── UPGRADE PAYMENT (USD contract → exact local charge) ──────────────

    public function test_upgrade_payment_charges_exact_local_amount_after_conversion(): void
    {
        $this->mockPesapal();

        $nextBillingDate = Carbon::now()->addDays(20)->startOfDay();
        $subscription = $this->createSubscription($this->professional, $nextBillingDate);
        $expected = $this->expectedProration($this->professional, $this->enterprise, $nextBillingDate);

        // Confirm upgrade intent → stores pending amount
        $this->withHeaders($this->authHeaders())
            ->postJson("/api/v1/subscriptions/{$subscription->id}/upgrade", [
                'to_plan_id' => $this->enterprise->id,
                'effective' => 'immediate',
                'billing_cycle' => 'monthly',
            ])->assertStatus(200);

        // Frontend sends the USD proration figure (backend contract); backend converts to UGX.
        $response = $this->withHeaders($this->authHeaders())
            ->postJson('/api/v1/billing/payments/initiate', [
                'gateway_name' => 'pesapal',
                'phone' => '0771234567',
                'amount' => $expected['due'],
                'currency' => 'UGX',
                'payment_type' => 'upgrade_proration',
                'metadata' => ['action' => 'upgrade', 'to_plan_id' => $this->enterprise->id, 'billing_cycle' => 'monthly'],
            ]);

        $response->assertStatus(201);

        $this->assertDatabaseHas('billing_payments', [
            'business_id' => $this->business->id,
            'subscription_id' => $subscription->id,
            'amount' => $this->usdToLocal($expected['due']),
            'currency' => 'UGX',
            'payment_type' => 'upgrade_proration',
            'status' => 'pending',
        ]);
    }

    public function test_upgrade_payment_rejects_tampered_amount(): void
    {
        $this->mockPesapal();

        $nextBillingDate = Carbon::now()->addDays(20)->startOfDay();
        $subscription = $this->createSubscription($this->professional, $nextBillingDate);
        $expected = $this->expectedProration($this->professional, $this->enterprise, $nextBillingDate);
        $this->assertGreaterThan(0.0, $expected['due']);

        // Confirm upgrade intent → stores the authoritative pending amount
        $this->withHeaders($this->authHeaders())
            ->postJson("/api/v1/subscriptions/{$subscription->id}/upgrade", [
                'to_plan_id' => $this->enterprise->id,
                'effective' => 'immediate',
                'billing_cycle' => 'monthly',
            ])->assertStatus(200);

        // Client tampers the amount ($1 instead of the pending USD proration)
        // → backend must reject and create NO payment record.
        $response = $this->withHeaders($this->authHeaders())
            ->postJson('/api/v1/billing/payments/initiate', [
                'gateway_name' => 'pesapal',
                'phone' => '0771234567',
                'amount' => 1.00,
                'currency' => 'USD',
                'payment_type' => 'upgrade_proration',
                'metadata' => ['action' => 'upgrade', 'to_plan_id' => $this->enterprise->id, 'billing_cycle' => 'monthly'],
            ]);

        $response->assertStatus(502);

        $this->assertDatabaseMissing('billing_payments', [
            'subscription_id' => $subscription->id,
            'payment_type' => 'upgrade_proration',
        ]);
    }

    // ─── TOPPED-UP WINDOW (multi-month prepaid coverage) ──────────────────

    public function test_topup_upgrade_payment_preserves_paid_through_date_on_approval(): void
    {
        $this->mockPesapal();

        // Paid-through date 122 days out (3-month top-up), coverage still monthly.
        $nextBillingDate = Carbon::now()->addDays(122)->startOfDay();
        $subscription = $this->createSubscription($this->professional, $nextBillingDate, 'active', 'monthly');
        $expected = $this->expectedProration($this->professional, $this->enterprise, $nextBillingDate);
        $this->assertGreaterThan(0.0, $expected['due']);

        // 1. Confirm upgrade intent → stores the pending amount + target plan
        $this->withHeaders($this->authHeaders())
            ->postJson("/api/v1/subscriptions/{$subscription->id}/upgrade", [
                'to_plan_id' => $this->enterprise->id,
                'effective' => 'immediate',
                'billing_cycle' => 'monthly',
            ])->assertStatus(200);

        // 2. Initiate the upgrade payment (frontend sends the USD proration due)
        $initiate = $this->withHeaders($this->authHeaders())
            ->postJson('/api/v1/billing/payments/initiate', [
                'gateway_name' => 'pesapal',
                'phone' => '0771234567',
                'amount' => $expected['due'],
                'currency' => 'USD',
                'payment_type' => 'upgrade_proration',
                'metadata' => ['action' => 'upgrade', 'to_plan_id' => $this->enterprise->id, 'billing_cycle' => 'monthly'],
            ]);
        $initiate->assertStatus(201);
        $paymentId = (int) $initiate->json('payment_id');

        // 3. Approve the payment through the gateway verification path
        app(\App\Services\Payment\GatewayService::class)->confirmPayment($paymentId);

        // 4. Plan switched instantly, paid-through date preserved, pending metadata cleared
        $subscription->refresh();
        $this->assertSame($this->enterprise->id, (int) $subscription->plan_id);
        $this->assertSame($nextBillingDate->toDateTimeString(), $subscription->next_billing_date->toDateTimeString());

        $metadata = $subscription->metadata ?? [];
        $this->assertArrayNotHasKey('pending_upgrade_amount_usd', $metadata);
        $this->assertArrayNotHasKey('pending_upgrade_to_plan_id', $metadata);
        $this->assertArrayNotHasKey('pending_upgrade_billing_cycle', $metadata);

        $this->assertDatabaseHas('billing_payments', [
            'subscription_id' => $subscription->id,
            'payment_type' => 'upgrade_proration',
            'status' => 'completed',
        ]);
    }
}
