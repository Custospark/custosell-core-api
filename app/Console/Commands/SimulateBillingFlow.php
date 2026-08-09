<?php

namespace App\Console\Commands;

use App\Models\BillingCredit;
use App\Models\BillingPayment;
use App\Models\CreditApplication;
use App\Models\Referral;
use App\Models\Subscription;
use App\Services\Currency\Contracts\CurrencyExchangeServiceInterface;
use Illuminate\Console\Command;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Throwable;

class SimulateBillingFlow extends Command
{
    protected $signature = 'simulate:billing-flow {business=26 : Business ID}
        {--email=steve@gmail.com : Login email} {--password=12345678 : Login password}
        {--referral=DEZFQBBB : Referral/promo code to onboard with} {--keep-data : Skip reset}';

    protected $description = 'API-driven simulation of the onboarding → subscribe → topup → upgrade billing flow with full assertions and logging';

    protected CurrencyExchangeServiceInterface $currency;

    protected string $token = '';

    protected string $baseUrl = '/api/v1';

    public function handle(CurrencyExchangeServiceInterface $currency): int
    {
        $this->currency = $currency;

        $businessId = (int) $this->argument('business');
        $this->components->info("Billing flow simulation — business #{$businessId}");
        $this->newLine();

        try {
            $this->login();
            $this->resetIfNeeded($businessId);

            $subscriptionId = $this->stepSubscribe($businessId);
            $this->stepOnboardingPayment($subscriptionId);
            $this->stepSubscriptionPayment($subscriptionId);
            $this->stepTopupPayment($subscriptionId);
            $this->stepUpgrade($subscriptionId);
            $this->stepSummary($businessId);

            $this->newLine();
            $this->components->success('Simulation complete — all assertions passed. Oscar, the flow is verified end-to-end.');
            return Command::SUCCESS;
        } catch (Throwable $e) {
            $this->components->error("Simulation failed: {$e->getMessage()}");
            if ($this->output->isVerbose()) {
                $this->components->error($e->getTraceAsString());
            }
            return Command::FAILURE;
        }
    }

    protected function login(): void
    {
        $this->components->info('Step 0 — Login via real endpoint POST /auth/login');
        $res = $this->callApi('POST', '/auth/login', [
            'email' => $this->option('email'),
            'password' => $this->option('password'),
        ]);
        $this->assertIs2xx($res, 'Login should succeed');
        $this->token = $res['body']['token'] ?? '';
        if (!$this->token) { throw new \RuntimeException('Login response missing token'); }
        $email = $res['body']['user']['email'] ?? $this->option('email');
        $this->safeLog('token-acquired', ['email' => $email, 'token_len' => strlen($this->token)]);
        $this->newLine();
    }

    protected function resetIfNeeded(int $businessId): void
    {
        if ($this->option('keep-data')) {
            $this->components->warn('Skipping reset (--keep-data); reusing existing billing state.');
            $this->newLine();
            return;
        }

        $this->components->info("Step 1 — Reset subscription/billing data for business #{$businessId}");
        $subIds = DB::table('subscriptions')->where('business_id', $businessId)->pluck('id')->all();
        $paymentIds = DB::table('billing_payments')->where('business_id', $businessId)->pluck('id')->all();
        $creditIds = DB::table('billing_credits')
            ->where(function ($q) use ($businessId) {
                $q->where('owner_type', 'business')->where('owner_id', $businessId);
            })
            ->orWhereIn('referral_id', function ($q) use ($businessId) {
                $q->select('id')->from('referrals')->where('referred_business_id', $businessId);
            })
            ->pluck('id')->all();

        DB::transaction(function () use ($businessId, $subIds, $paymentIds, $creditIds) {
            $appQ = DB::table('credit_applications');
            if ($subIds) $appQ->whereIn('subscription_id', $subIds);
            if ($paymentIds) $appQ->orWhereIn('billing_payment_id', $paymentIds);
            if ($creditIds) $appQ->orWhereIn('credit_id', $creditIds);
            if ($subIds || $paymentIds || $creditIds) $appQ->delete();

            if ($creditIds) DB::table('billing_credits')->whereIn('id', $creditIds)->delete();
            if ($paymentIds) DB::table('billing_payments')->whereIn('id', $paymentIds)->delete();
            DB::table('referrals')->where('referred_business_id', $businessId)->delete();
            DB::table('subscription_scheduled_changes')->where('business_id', $businessId)->delete();
            DB::table('subscriptions')->where('business_id', $businessId)->delete();
        });

        $this->safeLog('reset-complete', [
            'subscriptions_removed' => count($subIds),
            'payments_removed' => count($paymentIds),
            'credits_removed' => count($creditIds),
        ]);
        $this->newLine();
    }

    protected function stepSubscribe(int $businessId): int
    {
        $this->components->info('Step 2 — Subscribe (plan 6 Professional, monthly) with referral ' . $this->option('referral'));
        $res = $this->callApi('POST', '/subscriptions/subscribe', [
            'plan_id' => 6,
            'billing_cycle' => 'monthly',
            'referral_code' => $this->option('referral'),
        ]);
        $this->assertIs2xx($res, 'Subscribe should succeed');

        $data = $res['body']['data'] ?? [];
        $subscriptionId = (int) ($data['id'] ?? 0);
        if (!$subscriptionId) {
            throw new \RuntimeException('Subscribe response missing subscription id');
        }

        $sub = Subscription::find($subscriptionId);
        $this->assert($sub !== null, 'Subscription persisted');
        $this->assert($sub->plan_id === 6, 'Subscribed to Professional plan');
        $this->assert($this->str($sub->status) === 'trial', "Subscription should be on trial, got {$this->str($sub->status)}");
        $this->assert($sub->trial_ends_at?->isFuture(), 'Trial end in future');

        $referral = Referral::where('subscription_id', $subscriptionId)
            ->where('referred_business_id', $businessId)->first();
        $this->assert($referral !== null, 'Referral created and PENDING');
        if ($referral) {
            $this->assert($this->str($referral->status) === 'pending', "Referral should be PENDING, got {$this->str($referral->status)}");
        }

        $this->assertRow('subscription-after-subscribe', 'subscriptions', $subscriptionId, [
            'plan_id' => $sub->plan_id,
            'status' => $this->str($sub->status),
            'billing_cycle' => $sub->billing_cycle,
            'trial_ends_at' => $sub->trial_ends_at?->toDateString(),
            'next_billing_date' => $sub->next_billing_date?->toDateString(),
        ]);
        $this->assertRow('referral-after-subscribe', 'referrals', $referral->id, [
            'status' => $this->str($referral->status),
            'discount_applied' => (float) $referral->discount_applied,
            'reward_amount' => (float) $referral->reward_amount,
        ]);
        $this->newLine();
        return $subscriptionId;
    }

    protected function stepOnboardingPayment(int $subscriptionId): void
    {
        $this->components->info('Step 3 — Onboarding payment (payment_type=onboarding)');
        $feeUsd = 95.00;
        $discountUsd = round($feeUsd * 0.30, 2); // DEZFQBBB = 30% off first 2 periods
        $chargeUsd = round($feeUsd - $discountUsd, 2);

        $res = $this->callApi('POST', '/billing/payments/initiate', [
            'gateway_name' => 'pesapal',
            'amount' => $feeUsd,
            'currency' => 'UGX',
            'payment_type' => 'onboarding',
            'billing_cycle' => 'monthly',
            'phone' => '+256700000000',
            'metadata' => ['plan_id' => 6],
        ]);
        $this->assertIs2xx($res, 'Onboarding initiate should succeed');
        $resBody = $res['body'];
        $this->assert(!empty($resBody['success']), 'Onboarding initiate success flag');
        $this->assert($resBody['type'] === 'bypass', 'Onboarding bypass auto-approve');

        $paymentId = $resBody['payment_id'] ?? null;
        $this->assert($paymentId !== null, 'Onboarding payment id returned');
        $this->assertPayment($paymentId, 'onboarding', $chargeUsd);

        $referral = Referral::where('subscription_id', $subscriptionId)->first();
        $this->assert($referral !== null, 'Referral still exists');
        if ($referral) {
            $this->assert($this->str($referral->status) === 'active', "Referral ACTIVE after onboarding, got {$this->str($referral->status)}");
            $this->assert(abs((float) $referral->discount_applied - $discountUsd) < 0.01, 'Referral discount_applied == 28.50');
            $this->assert((float) $referral->reward_amount === 0.0, 'Campaign code earns 0 reward (intentional)');
            $this->safeLog('referral-after-onboarding', [
                'status' => $this->str($referral->status),
                'discount_applied' => (float) $referral->discount_applied,
                'reward_amount' => (float) $referral->reward_amount,
                'converted_at' => $referral->converted_at?->toIso8601String(),
            ]);
        }

        $sub = Subscription::find($subscriptionId);
        $this->assert($sub->onboarding_fee_paid, 'onboarding_fee_paid set');
        $this->assert($this->str($sub->status) === 'trial', "Still on trial after onboarding (got {$this->str($sub->status)})");

        $credit = BillingCredit::where('owner_type', 'business')
            ->where('owner_id', $sub->business_id)->orderByDesc('id')->first();
        $this->assert($credit !== null, 'Referee credit created after onboarding');
        if ($credit) {
            $this->assert(abs((float) $credit->amount - 16.20) < 0.01, 'Credit amount == $16.20 (30% of $54)');
            $this->assert($this->str($credit->status) === 'available', 'Credit available');
            $this->assertRow('credit-after-onboarding', 'billing_credits', $credit->id, [
                'amount' => (float) $credit->amount,
                'amount_used' => (float) $credit->amount_used,
                'status' => $credit->status,
            ]);
        }
        $this->newLine();
    }

    protected function stepSubscriptionPayment(int $subscriptionId): void
    {
        $this->components->info('Step 4 — Subscribe now (payment_type=subscription, monthly $54)');
        $monthlyUsd = 54.00;
        $creditUsd = 16.20;
        $chargeUsd = round($monthlyUsd - $creditUsd, 2);

        $res = $this->callApi('POST', '/billing/payments/initiate', [
            'gateway_name' => 'pesapal',
            'amount' => $monthlyUsd,
            'currency' => 'UGX',
            'payment_type' => 'subscription',
            'billing_cycle' => 'monthly',
            'phone' => '+256700000000',
            'metadata' => [],
        ]);
        $this->assertIs2xx($res, 'Subscribe-now initiate should succeed');
        $resBody = $res['body'];
        $this->assert($resBody['type'] === 'bypass', 'Subscribe-now bypass auto-approve');

        $paymentId = $resBody['payment_id'] ?? null;
        $this->assert($paymentId !== null, 'Subscription payment id returned');
        $this->assertPayment($paymentId, 'subscription', $chargeUsd);

        $sub = Subscription::find($subscriptionId);
        $this->assert($this->str($sub->status) === 'active', "Subscription ACTIVE after first monthly payment (got {$this->str($sub->status)})");
        $this->assert($sub->trial_ends_at !== null, 'Trial end preserved on activation');
        $this->assertRow('subscription-after-subscribe-now', 'subscriptions', $subscriptionId, [
            'plan_id' => $sub->plan_id,
            'status' => $this->str($sub->status),
            'billing_cycle' => $sub->billing_cycle,
            'trial_ends_at' => $sub->trial_ends_at?->toDateString(),
            'next_billing_date' => $sub->next_billing_date?->toDateString(),
        ]);

        $credit = BillingCredit::where('owner_type', 'business')->where('owner_id', $sub->business_id)->orderByDesc('id')->first();
        $this->assert($credit !== null && $this->str($credit->status) === 'fully_used', 'Credit fully used after first monthly charge');
        if ($credit) {
            $this->assert(abs((float) $credit->amount_used - $creditUsd) < 0.01, 'Credit consumed = $16.20');
        }
        $this->newLine();
    }

    protected function stepTopupPayment(int $subscriptionId): void
    {
        $this->components->info('Step 5 — Top-up (payment_type=topup, 6 months)');
        $monthlyUsd = 54.00;
        $months = 6;
        $chargeUsd = round($monthlyUsd * $months, 2);

        $res = $this->callApi('POST', '/billing/payments/initiate', [
            'gateway_name' => 'pesapal',
            'amount' => $chargeUsd,
            'currency' => 'UGX',
            'payment_type' => 'topup',
            'billing_cycle' => 'monthly',
            'topup_months' => $months,
            'phone' => '+256700000000',
            'metadata' => [],
        ]);
        $this->assertIs2xx($res, 'Top-up initiate should succeed');
        $resBody = $res['body'];
        $this->assert($resBody['type'] === 'bypass', 'Top-up bypass auto-approve');

        $paymentId = $resBody['payment_id'] ?? null;
        $this->assert($paymentId !== null, 'Top-up payment id returned');
        $this->assertPayment($paymentId, 'topup', $chargeUsd);

        $sub = Subscription::find($subscriptionId);
        $this->assert($this->str($sub->status) === 'active', 'Still active after topup');
        $this->assert(!empty($sub->metadata['topup_months']), 'topup_months stored in metadata');
        $this->assertRow('subscription-after-topup', 'subscriptions', $subscriptionId, [
            'status' => $this->str($sub->status),
            'next_billing_date' => $sub->next_billing_date?->toDateString(),
            'topup_months' => $sub->metadata['topup_months'] ?? null,
        ]);
        $this->newLine();
    }

    protected function stepUpgrade(int $subscriptionId): void
    {
        $this->components->info('Step 6 — Upgrade to Enterprise (plan 8) yearly');

        $quote = $this->callApi('GET', "/subscriptions/{$subscriptionId}/proration-quote", [
            'to_plan_id' => 8,
            'billing_cycle' => 'yearly',
        ]);
        $this->assertIs2xx($quote, 'Proration quote should succeed');
        $quoteData = $quote['body']['data'] ?? $quote['body'] ?? [];
        $this->assertRow('proration-quote', 'quote', 0, $quoteData);

        $upgrade = $this->callApi('POST', "/subscriptions/{$subscriptionId}/upgrade", [
            'to_plan_id' => 8,
            'effective' => 'immediate',
            'billing_cycle' => 'yearly',
        ]);
        $this->assertIs2xx($upgrade, 'Upgrade should succeed');
        $upgradeQuote = $upgrade['body']['proration'] ?? [];
        $this->assertRow('upgrade-quote', 'quote', 0, $upgradeQuote);

        $prorationDueUsd = (float) ($upgradeQuote['proration']['proration_due_usd']
            ?? $upgradeQuote['proration']['proration_due']
            ?? 0);
        $this->assert($prorationDueUsd > 0, 'Upgrade proration due > 0');

        $initiate = $this->callApi('POST', '/billing/payments/initiate', [
            'gateway_name' => 'pesapal',
            'amount' => $prorationDueUsd,
            'currency' => 'UGX',
            'payment_type' => 'upgrade_proration',
            'billing_cycle' => 'yearly',
            'phone' => '+256700000000',
            'metadata' => [
                'action' => 'upgrade',
                'to_plan_id' => 8,
                'billing_cycle' => 'yearly',
            ],
        ]);
        $this->assertIs2xx($initiate, 'Upgrade payment initiate should succeed');
        $initBody = $initiate['body'];
        $this->assert($initBody['type'] === 'bypass', 'Upgrade payment bypass auto-approve');

        $paymentId = $initBody['payment_id'] ?? null;
        $this->assert($paymentId !== null, 'Upgrade payment id returned');
        $this->assertPayment($paymentId, 'upgrade_proration', $prorationDueUsd);

        $sub = Subscription::find($subscriptionId);
        $this->assert($sub->plan_id === 8, 'Upgraded to Enterprise plan 8');
        $this->assert($this->str($sub->billing_cycle) === 'yearly', "Billing cycle yearly, got {$this->str($sub->billing_cycle)}");
        $this->assert(abs((float) $sub->price_yearly_usd - 1350.00) < 0.01, 'Yearly price now $1350');
        $this->assertRow('subscription-after-upgrade', 'subscriptions', $subscriptionId, [
            'plan_id' => $sub->plan_id,
            'status' => $this->str($sub->status),
            'billing_cycle' => $sub->billing_cycle,
            'price_yearly_usd' => (float) $sub->price_yearly_usd,
            'next_billing_date' => $sub->next_billing_date?->toDateString(),
        ]);

        $this->assertScheduledChange($subscriptionId);
        $this->newLine();
    }

    protected function stepSummary(int $businessId): void
    {
        $this->components->info('Step 7 — Final state and payment confirmations');
        $sub = Subscription::where('business_id', $businessId)->first();
        $payments = BillingPayment::where('business_id', $businessId)->orderBy('id')->get();
        $referral = Referral::where('referred_business_id', $businessId)->first();
        $credit = BillingCredit::where('owner_type', 'business')->where('owner_id', $businessId)->first();

        $this->newLine();
        $this->components->twoColumnDetail('Subscription', $sub ? "#{$sub->id} — plan {$sub->plan_id} {$sub->billing_cycle} ({$this->str($sub->status)})" : 'none');
        $this->components->twoColumnDetail('Next billing', $sub?->next_billing_date?->toDateString() ?? 'n/a');

        $this->components->twoColumnDetail('Payments', $payments->map(fn (BillingPayment $p) => "{$this->str($p->payment_type)}: {$p->currency} {$p->amount} [{$this->str($p->status)}]")->implode("\n"));

        $this->components->twoColumnDetail('Referral', $referral
            ? "{$this->str($referral->status)} — discount \${$referral->discount_applied}, reward \${$referral->reward_amount}"
            : 'none');

        $this->components->twoColumnDetail('Referee credit', $credit
            ? "\${$credit->amount} (used \${$credit->amount_used}) — {$this->str($credit->status)}"
            : 'none');
    }

    protected function assertPayment(int $paymentId, string $expectedType, float $expectedUsd): void
    {
        $payment = BillingPayment::find($paymentId);
        $this->assert($payment !== null, "Payment #{$paymentId} persisted");
        if (!$payment) return;

        $this->assert($this->str($payment->status) === 'completed', "Payment #{$paymentId} completed (got {$this->str($payment->status)})");
        $this->assert($this->str($payment->payment_type) === $expectedType, 'Payment type matches');
        $this->assert($payment->currency === 'UGX', 'Payment charged in UGX');

        $rate = $this->currency->getExchangeRate('USD', 'UGX');
        $expectedUgx = round($expectedUsd * $rate, 2);
        $this->assert(
            abs((float) $payment->amount - $expectedUgx) < 0.02,
            "Payment #{$paymentId} charged UGX {$payment->amount} ≈ expected UGX {$expectedUgx} (rate {$rate})"
        );
        $this->assertRow("payment-{$expectedType}", 'billing_payments', $paymentId, [
            'amount' => (float) $payment->amount,
            'currency' => $payment->currency,
            'payment_type' => $this->str($payment->payment_type),
            'status' => $this->str($payment->status),
            'paid_at' => $payment->paid_at?->toIso8601String(),
            'metadata' => $payment->metadata,
        ]);
    }

    protected function assertScheduledChange(int $subscriptionId): void
    {
        $change = DB::table('subscription_scheduled_changes')
            ->where('subscription_id', $subscriptionId)
            ->where('change_type', 'upgrade')
            ->orderByDesc('id')
            ->first();
        $this->assert($change !== null, 'Upgrade scheduled change recorded');
        if ($change) {
            $this->assert($this->str($change->status) === 'applied', "Scheduled change applied (got {$this->str($change->status)})");
            $this->assert((int) $change->to_plan_id === 8, 'Scheduled change targets Enterprise');
        }
    }

    protected function callApi(string $method, string $path, array $data = []): array
    {
        $uri = $this->baseUrl . $path;
        if ($method === 'GET' && $data) {
            $uri .= '?' . http_build_query($data);
        }

        $request = Request::create($uri, $method, $data);
        $request->headers->set('Accept', 'application/json');
        if ($this->token) {
            $request->headers->set('Authorization', 'Bearer ' . $this->token);
        }

        /** @var HttpKernel $kernel */
        $kernel = app(HttpKernel::class);
        $response = $kernel->handle($request);
        $status = $response->getStatusCode();
        $body = json_decode((string) $response->getContent(), true) ?: [];
        $kernel->terminate($request, $response);

        $this->safeLog("http-{$method}-{$path}", [
            'status' => $status,
            'response' => $body,
        ]);

        return ['status' => $status, 'body' => $body];
    }

    protected function assertIs2xx(array $res, string $message): void
    {
        $status = $res['status'];
        $this->assert($status >= 200 && $status < 300, "{$message} (HTTP {$status})");
        if ($status >= 400) {
            throw new \RuntimeException(
                "{$message} — HTTP {$status}: " . json_encode($res['body'] ?? [])
            );
        }
    }

    protected function assert(bool $condition, string $message): void
    {
        $this->assertRow($condition ? 'PASS' : 'FAIL', 'assert', 0, ['condition' => $message]);
        if (!$condition) {
            throw new \RuntimeException("Assertion failed: {$message}");
        }
    }

    protected function assertRow(string $label, string $table, int $id, array $row): void
    {
        $this->line(sprintf(
            '  %-28s %s',
            "[{$label}]",
            json_encode($row, JSON_UNESCAPED_SLASHES)
        ));
    }

    protected function safeLog(string $label, array $data): void
    {
        \Illuminate\Support\Facades\Log::info("[BillingSim] {$label}", $data);
    }

    protected function str(mixed $value): string
    {
        if (is_string($value)) {
            return $value;
        }
        if ($value instanceof \BackedEnum) {
            return (string) $value->value;
        }
        return (string) $value;
    }
}