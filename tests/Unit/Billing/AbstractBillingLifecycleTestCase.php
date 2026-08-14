<?php

namespace Tests\Unit\Billing;

use App\Models\Business;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Services\Billing\PaymentQuoteService;
use App\Services\Billing\PaymentService;
use App\Services\Billing\SubscriptionProrationCalculator;
use App\Services\Billing\SubscriptionScheduledChangeService;
use App\Services\Payment\GatewayService;
use App\Services\SubscriptionService;
use Database\Seeders\PlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

abstract class AbstractBillingLifecycleTestCase extends TestCase
{
    use RefreshDatabase;

    protected SubscriptionService $subscriptionService;
    protected PaymentService $paymentService;
    protected GatewayService $gatewayService;
    protected SubscriptionScheduledChangeService $scheduledChangeService;
    protected PaymentQuoteService $paymentQuoteService;
    protected SubscriptionProrationCalculator $prorationCalculator;

    protected Plan $essential;
    protected Plan $professional;
    protected Plan $enterprise;
    protected Plan $noTrial;

    // ─── User Story 1: Grace Hopper ─────────────────────────────────────
    protected User $grace;
    protected Business $aceHardware;

    // ─── User Story 2: Alan Turing ────────────────────────────────────────
    protected User $alan;
    protected Business $enigmaTech;

    // ─── User Story 3: Margaret Hamilton ──────────────────────────────────
    protected User $margaret;
    protected Business $apolloSoft;

    // ─── User Story 4: Tim Berners-Lee ────────────────────────────────────
    protected User $tim;
    protected Business $webFoundation;

    // ─── User Story 5: Ada Lovelace ───────────────────────────────────────
    protected User $ada;
    protected Business $analyticalEngine;

    // ─── User Story 6: Linus Torvalds ─────────────────────────────────────
    protected User $linus;
    protected Business $linuxFdn;

    // ─── User Story 7: Dennis Ritchie ─────────────────────────────────────
    protected User $dennis;
    protected Business $bellLabs;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PlanSeeder::class);

        $this->essential = Plan::where('slug', 'essential')->first();
        $this->professional = Plan::where('slug', 'professional')->first();
        $this->enterprise = Plan::where('slug', 'enterprise')->first();

        $this->noTrial = Plan::create([
            'name' => 'Starter',
            'slug' => 'starter',
            'description' => 'No-trial plan',
            'price_monthly_usd' => 8,
            'price_yearly_usd' => 80,
            'onboarding_fee_usd' => 15,
            'trial_days' => 0,
            'billing_cycle' => 'both',
            'features' => ['sales' => true],
            'limits' => ['max_staff' => 1, 'max_products' => 100],
            'is_active' => true,
            'sort_order' => 99,
        ]);

        $this->subscriptionService = app(SubscriptionService::class);
        $this->paymentService = app(PaymentService::class);
        $this->gatewayService = app(GatewayService::class);
        $this->scheduledChangeService = app(SubscriptionScheduledChangeService::class);
        $this->paymentQuoteService = app(PaymentQuoteService::class);
        $this->prorationCalculator = app(SubscriptionProrationCalculator::class);

        // Grace Hopper - Ace Hardware Kikuubo
        $this->grace = User::factory()->create(['name' => 'Grace Hopper', 'email' => 'grace@acehardware.com', 'is_active' => true]);
        $this->aceHardware = Business::factory()->create(['owner_id' => $this->grace->id, 'name' => 'Ace Hardware Kikuubo', 'currency' => 'UGX']);

        // Alan Turing - Enigma Tech Solutions
        $this->alan = User::factory()->create(['name' => 'Alan Turing', 'email' => 'alan@enigmatech.com', 'is_active' => true]);
        $this->enigmaTech = Business::factory()->create(['owner_id' => $this->alan->id, 'name' => 'Enigma Tech Solutions', 'currency' => 'UGX']);

        // Margaret Hamilton - Apollo Software Ltd
        $this->margaret = User::factory()->create(['name' => 'Margaret Hamilton', 'email' => 'margaret@apollosoft.com', 'is_active' => true]);
        $this->apolloSoft = Business::factory()->create(['owner_id' => $this->margaret->id, 'name' => 'Apollo Software Ltd', 'currency' => 'UGX']);

        // Tim Berners-Lee - Web Foundation
        $this->tim = User::factory()->create(['name' => 'Tim Berners-Lee', 'email' => 'tim@webfoundation.org', 'is_active' => true]);
        $this->webFoundation = Business::factory()->create(['owner_id' => $this->tim->id, 'name' => 'Web Foundation', 'currency' => 'UGX']);

        // Ada Lovelace - Analytical Engine Co
        $this->ada = User::factory()->create(['name' => 'Ada Lovelace', 'email' => 'ada@analyticalengine.com', 'is_active' => true]);
        $this->analyticalEngine = Business::factory()->create(['owner_id' => $this->ada->id, 'name' => 'Analytical Engine Co', 'currency' => 'UGX']);

        // Linus Torvalds - Linux Foundation
        $this->linus = User::factory()->create(['name' => 'Linus Torvalds', 'email' => 'linus@linuxfoundation.org', 'is_active' => true]);
        $this->linuxFdn = Business::factory()->create(['owner_id' => $this->linus->id, 'name' => 'Linux Foundation', 'currency' => 'UGX']);

        // Dennis Ritchie - Bell Labs Computing
        $this->dennis = User::factory()->create(['name' => 'Dennis Ritchie', 'email' => 'dennis@bell-labs.com', 'is_active' => true]);
        $this->bellLabs = Business::factory()->create(['owner_id' => $this->dennis->id, 'name' => 'Bell Labs Computing', 'currency' => 'UGX']);
    }

    protected function subscribeAndActivateEssential(Business $business): Subscription
    {
        $subscription = $this->subscriptionService->subscribe(
            $business->id,
            $this->essential->id,
            'monthly',
        );

        return $this->subscriptionService->activateSubscription($subscription);
    }
}
