<?php

namespace Tests\Unit\Services;

use App\Models\BillingPayment;
use App\Models\Business;
use App\Models\JournalEntry;
use App\Models\JournalEntryLine;
use App\Models\Payout;
use App\Models\Subscription;
use App\Models\User;
use App\Services\CompanyAccountingService;
use App\Services\Currency\Contracts\CurrencyExchangeServiceInterface;
use Database\Seeders\PlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use Tests\Support\SeedsAccounting;

/**
 * Proves Custospark's company books are posted for money received (subscription
 * payments) and money paid out (referral/commission payouts), with real numbers:
 *
 *   - subscription payment of 1,000,000 UGX at 1 USD = 3708.59 UGX
 *     → Dr Bank 1102 = 269.64 USD / Cr Deferred Revenue 2106 = 269.64 USD
 *   - payout of 50 USD → Dr Referral & Commission Expense 6901 / Cr Bank 1102
 *   - same-currency payments skip conversion entirely
 *   - dispatching twice never double-books (idempotent by reference)
 */
class CompanyAccountingTest extends TestCase
{
    use RefreshDatabase;
    use SeedsAccounting;

    private const RATE = 3708.59;

    protected User $companyOwner;

    protected Business $company;

    protected User $customerOwner;

    protected Business $customer;

    protected Subscription $subscription;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(PlanSeeder::class);

        // Custospark's own ledger business (USD) owned by the company owner.
        $this->companyOwner = User::factory()->create([
            'email' => 'oscar@custospark.com',
            'is_active' => true,
        ]);
        $this->company = Business::factory()->create([
            'owner_id' => $this->companyOwner->id,
            'name' => 'Custospark',
            'slug' => 'custospark',
            'email' => 'oscar@custospark.com',
            'currency' => 'USD',
            'status' => 'active',
        ]);

        $this->seedAccountingForBusiness($this->company);

        // A paying customer business (UGX) + its subscription.
        $this->customerOwner = User::factory()->create(['is_active' => true]);
        $this->customer = Business::factory()->create([
            'owner_id' => $this->customerOwner->id,
            'currency' => 'UGX',
            'status' => 'active',
        ]);
        $this->subscription = $this->ensureSubscription($this->customer->id);

        // Fixed exchange rate for the test. convert(amount, to, from): UGX -> USD
        // divides by RATE; same-currency returns unchanged.
        $this->mock(CurrencyExchangeServiceInterface::class, function ($mock) {
            $mock->shouldReceive('convert')
                ->andReturnUsing(fn ($amount, $to, $from = 'USD') =>
                    strtoupper($to) === strtoupper($from)
                        ? $amount
                        : (strtoupper($from) === 'USD'
                            ? round($amount * self::RATE, 2)
                            : round($amount / self::RATE, 2)),
                );
        });
    }

    protected function accountingService(): CompanyAccountingService
    {
        return app(CompanyAccountingService::class);
    }

    protected function makeCompletedPayment(float $amount, string $currency, string $type = 'subscription'): BillingPayment
    {
        return BillingPayment::create([
            'business_id' => $this->customer->id,
            'subscription_id' => $this->subscription->id,
            'user_id' => $this->customerOwner->id,
            'amount' => $amount,
            'currency' => $currency,
            'method' => 'gateway',
            'payment_type' => $type,
            'status' => 'completed',
            'transaction_reference' => 'TXN-'.uniqid(),
            'gateway_name' => 'test_gateway',
            'paid_at' => now(),
            'approved_at' => now(),
        ]);
    }

    protected function makePaidPayout(float $amount, string $currency = 'USD'): Payout
    {
        return Payout::create([
            'payable_type' => User::class,
            'payable_id' => $this->customerOwner->id,
            'amount' => $amount,
            'currency' => $currency,
            'status' => 'paid',
            'payment_method' => 'bank',
            'paid_at' => now(),
        ]);
    }

    protected function linesFor(Business $business, string $refType, int $refId): array
    {
        $entry = JournalEntry::query()
            ->where('business_id', $business->id)
            ->where('reference_type', $refType)
            ->where('reference_id', $refId)
            ->first();

        if (!$entry) {
            return [];
        }

        return JournalEntryLine::query()
            ->where('entry_id', $entry->id)
            ->with('chartOfAccount')
            ->get()
            ->mapWithKeys(fn ($line) => [$line->chartOfAccount->code => [
                'debit' => (float) $line->debit_amount,
                'credit' => (float) $line->credit_amount,
            ]])
            ->toArray();
    }

    public function test_subscription_payment_is_converted_into_company_currency(): void
    {
        // Customer pays 1,000,000 UGX for their subscription.
        $payment = $this->makeCompletedPayment(1_000_000, 'UGX');

        $this->accountingService()->accountForSubscriptionPayment($payment);

        // 1,000,000 UGX / 3708.59 = 269.64 USD.
        $lines = $this->linesFor($this->company, 'platform_subscription_payment', $payment->id);

        $this->assertCount(2, $lines);
        $this->assertEqualsWithDelta(269.64, $lines['1102']['debit'], 0.01);   // Dr Bank
        $this->assertEqualsWithDelta(269.64, $lines['2106']['credit'], 0.01);  // Cr Deferred Revenue
    }

    public function test_same_currency_payment_skips_conversion(): void
    {
        // Company currency is USD; a USD payment books USD unchanged.
        $payment = $this->makeCompletedPayment(50, 'USD');

        $this->accountingService()->accountForSubscriptionPayment($payment);

        $lines = $this->linesFor($this->company, 'platform_subscription_payment', $payment->id);

        $this->assertCount(2, $lines);
        $this->assertEqualsWithDelta(50, $lines['1102']['debit'], 0.01);
        $this->assertEqualsWithDelta(50, $lines['2106']['credit'], 0.01);
    }

    public function test_subscription_payment_is_idempotent_per_payment(): void
    {
        $payment = $this->makeCompletedPayment(50, 'USD');

        $this->accountingService()->accountForSubscriptionPayment($payment);
        $this->accountingService()->accountForSubscriptionPayment($payment);

        $count = JournalEntry::query()
            ->where('business_id', $this->company->id)
            ->where('reference_type', 'platform_subscription_payment')
            ->where('reference_id', $payment->id)
            ->count();

        $this->assertSame(1, $count);
    }

    public function test_payout_is_booked_as_expense_against_bank(): void
    {
        $payout = $this->makePaidPayout(50, 'USD');

        $this->accountingService()->accountForPayout($payout);

        $lines = $this->linesFor($this->company, 'platform_payout', $payout->id);

        $this->assertCount(2, $lines);
        $this->assertEqualsWithDelta(50, $lines['6901']['debit'], 0.01);  // Dr Referral & Commission Expense
        $this->assertEqualsWithDelta(50, $lines['1102']['credit'], 0.01); // Cr Bank
    }

    public function test_payout_is_idempotent_per_payout(): void
    {
        $payout = $this->makePaidPayout(50, 'USD');

        $this->accountingService()->accountForPayout($payout);
        $this->accountingService()->accountForPayout($payout);

        $count = JournalEntry::query()
            ->where('business_id', $this->company->id)
            ->where('reference_type', 'platform_payout')
            ->where('reference_id', $payout->id)
            ->count();

        $this->assertSame(1, $count);
    }

    public function test_company_ledger_accounts_seeder_is_idempotent(): void
    {
        $seeder = new \Database\Seeders\CompanyAccountingAccountsSeeder();

        $seeder->run();
        $seeder->run();

        $codes = config('platform.company_accounting.account_codes');
        $expected = array_values($codes);

        $actual = \App\Models\ChartOfAccount::where('business_id', $this->company->id)
            ->whereIn('code', $expected)
            ->pluck('code')
            ->sort()
            ->values()
            ->all();

        sort($expected);

        $this->assertEquals($expected, $actual);
    }
}
