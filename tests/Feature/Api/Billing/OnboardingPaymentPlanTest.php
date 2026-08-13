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
 * Onboarding payment must be priced against the plan the user actually selected
 * on the onboarding page (metadata.plan_id), not the default plan (Essential)
 * that the subscription holds after registration.
 */
class OnboardingPaymentPlanTest extends TestCase
{
    use RefreshDatabase;

    private const RATE = 3708.59;

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

    protected function mockPesapal(): void
    {
        Config::set('pesapal.enabled', true);

        $this->mock(\App\Services\Payment\Gateways\PesaPalGateway::class, function ($mock) {
            $mock->shouldReceive('isEnabled')->andReturn(true);
            $mock->shouldReceive('getSupportedCurrencies')->andReturn(['UGX', 'KES', 'TZS', 'USD']);
            $mock->shouldReceive('initiate')->andReturn([
                'gateway_txn_id' => 'mock-txn-' . uniqid(),
                'gateway_ref' => 'mock-ref-' . uniqid(),
                'type' => 'redirect',
                'redirect_url' => 'https://pay.pesapal.com/mock',
                'message' => 'Success',
                'raw_response' => [],
            ]);
        });
    }

    protected function usdToLocal(float $usd): float
    {
        return round($usd * self::RATE, 2);
    }

    public function test_onboarding_payment_charges_plan_from_metadata_not_subscription_default(): void
    {
        $this->mockPesapal();

        // Business registered on the default Essential plan; user later picks Professional on the onboarding page.
        $subscription = Subscription::create([
            'business_id' => $this->business->id,
            'plan_id' => $this->essential->id,
            'status' => 'past_due',
            'billing_cycle' => 'monthly',
            'starts_at' => now()->subMonth(),
            'next_billing_date' => Carbon::now()->addMonth()->startOfDay(),
            'price_monthly_usd' => $this->essential->price_monthly_usd,
            'price_yearly_usd' => $this->essential->price_yearly_usd,
            'onboarding_fee_usd' => $this->essential->onboarding_fee_usd,
        ]);

        $response = $this->withHeaders($this->authHeaders())
            ->postJson('/api/v1/billing/payments/initiate', [
                'gateway_name' => 'pesapal',
                'phone' => '0771234567',
                'amount' => 5.00,
                'currency' => 'USD',
                'payment_type' => 'onboarding',
                'metadata' => ['action' => 'subscribe', 'plan_id' => $this->professional->id],
            ]);

        $response->assertStatus(201);

        // Charge must match the Professional onboarding fee, not Essential's $40.
        $this->assertDatabaseHas('billing_payments', [
            'business_id' => $this->business->id,
            'subscription_id' => $subscription->id,
            'amount' => $this->usdToLocal((float) $this->professional->onboarding_fee_usd),
            'currency' => 'UGX',
            'payment_type' => 'onboarding',
            'status' => 'pending',
        ]);
    }
}
