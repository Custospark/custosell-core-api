<?php

namespace Tests\Unit\Services;

use App\Models\AccountingPeriod;
use App\Models\BillingPayment;
use App\Models\Business;
use App\Models\JournalEntry;
use App\Models\JournalEntryLine;
use App\Models\Subscription;
use App\Models\User;
use App\Services\SubscriptionRevenueRecognitionService;
use Database\Seeders\PlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;
use Tests\Support\SeedsAccounting;

/**
 * Proves deferred subscription revenue is recognized into Software Revenue as
 * the coverage period is earned, pro-rated monthly:
 *
 *   - a 1-month USD subscription paid on 2026-06-01 fully earns on 2026-07-01
 *     → Dr Deferred Revenue / Cr Software Revenue for the full amount
 *   - mid-month starts earn only the elapsed fraction
 *   - re-running the same as-of date posts nothing (idempotent)
 *   - the ledger stays balanced (debits == credits)
 */
class SubscriptionRevenueRecognitionTest extends TestCase
{
    use RefreshDatabase;
    use SeedsAccounting;

    protected User $companyOwner;

    protected Business $company;

    protected User $customerOwner;

    protected Business $customer;

    protected Subscription $subscription;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(PlanSeeder::class);

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
        $this->openPeriods('2026-06-01', '2026-08-31');

        $this->customerOwner = User::factory()->create(['is_active' => true]);
        $this->customer = Business::factory()->create([
            'owner_id' => $this->customerOwner->id,
            'currency' => 'USD',
            'status' => 'active',
        ]);
        $this->subscription = $this->ensureSubscription($this->customer->id);
    }

    protected function openPeriods(string $from, string $to): void
    {
        $start = Carbon::parse($from)->startOfMonth();
        $end = Carbon::parse($to)->endOfMonth();

        for ($cursor = $start->copy(); $cursor->lte($end); $cursor->addMonth()) {
            AccountingPeriod::firstOrCreate(
                ['business_id' => $this->company->id, 'name' => $cursor->format('Y-m')],
                [
                    'start_date' => $cursor->copy()->startOfMonth()->toDateString(),
                    'end_date' => $cursor->copy()->endOfMonth()->toDateString(),
                    'is_closed' => false,
                ],
            );
        }
    }

    protected function completedPayment(float $amount, string $currency, string $paidDate, string $type = 'subscription'): BillingPayment
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
            'paid_at' => Carbon::parse($paidDate),
            'approved_at' => Carbon::parse($paidDate),
        ]);
    }

    protected function bookDeferred(BillingPayment $payment): void
    {
        app(\App\Services\CompanyAccountingService::class)->accountForSubscriptionPayment($payment);
    }

    protected function recognitionLines(int $paymentId): array
    {
        $entries = JournalEntry::query()
            ->where('business_id', $this->company->id)
            ->where('reference_type', 'platform_revenue_recognition')
            ->where('reference_id', $paymentId)
            ->get();

        $debits = [];
        $credits = [];
        foreach ($entries as $entry) {
            foreach (JournalEntryLine::query()->where('entry_id', $entry->id)->with('chartOfAccount')->get() as $line) {
                $code = $line->chartOfAccount->code;
                if ((float) $line->debit_amount > 0) {
                    $debits[$code] = ($debits[$code] ?? 0) + (float) $line->debit_amount;
                }
                if ((float) $line->credit_amount > 0) {
                    $credits[$code] = ($credits[$code] ?? 0) + (float) $line->credit_amount;
                }
            }
        }

        return ['debits' => $debits, 'credits' => $credits];
    }

    public function test_full_month_coverage_earns_full_amount_after_period_elapses(): void
    {
        $payment = $this->completedPayment(100, 'USD', '2026-06-01');
        $this->bookDeferred($payment);

        $service = app(SubscriptionRevenueRecognitionService::class);
        $service->recognizeForPayment($payment, Carbon::parse('2026-07-01'), $this->company);

        $lines = $this->recognitionLines($payment->id);

        $this->assertSame(100.0, $lines['debits']['2106'] ?? 0, 'Deferred Revenue must be debited the full amount.');
        $this->assertSame(100.0, $lines['credits']['4500'] ?? 0, 'Software Revenue must be credited the full amount.');
    }

    public function test_mid_period_earns_only_elapsed_fraction(): void
    {
        // Paid 2026-06-15, 1-month coverage ends 2026-07-14.
        // As of 2026-07-10 only the June bucket (Jun 15-30) has elapsed.
        $payment = $this->completedPayment(120, 'USD', '2026-06-15');
        $this->bookDeferred($payment);

        $service = app(SubscriptionRevenueRecognitionService::class);
        $service->recognizeForPayment($payment, Carbon::parse('2026-07-10'), $this->company);

        $lines = $this->recognitionLines($payment->id);
        $recognized = (float) ($lines['credits']['4500'] ?? 0);

        $this->assertGreaterThan(0, $recognized, 'Elapsed fraction must be recognized.');
        $this->assertLessThan(120, $recognized, 'Only the elapsed portion is recognized, not the full amount.');

        // June bucket: Jun 15..Jun 30 = 16 days of a 30-day coverage.
        $expected = round(120 * (16 / 30), 2);
        $this->assertEqualsWithDelta($expected, $recognized, 0.01);
    }

    public function test_recognition_is_idempotent_for_same_as_of_date(): void
    {
        $payment = $this->completedPayment(100, 'USD', '2026-06-01');
        $this->bookDeferred($payment);

        $service = app(SubscriptionRevenueRecognitionService::class);

        $first = $service->recognizeForPayment($payment, Carbon::parse('2026-07-01'), $this->company);
        $second = $service->recognizeForPayment($payment, Carbon::parse('2026-07-01'), $this->company);

        $this->assertEqualsWithDelta(100, $first, 0.01);
        $this->assertSame(0.0, $second, 'Re-running the same date must not double-post.');

        $count = JournalEntry::query()
            ->where('business_id', $this->company->id)
            ->where('reference_type', 'platform_revenue_recognition')
            ->where('reference_id', $payment->id)
            ->count();

        $this->assertSame(1, $count);
    }

    public function test_recognized_entries_keep_ledger_balanced(): void
    {
        $payment = $this->completedPayment(100, 'USD', '2026-06-01');
        $this->bookDeferred($payment);

        $service = app(SubscriptionRevenueRecognitionService::class);
        $service->recognizeForPayment($payment, Carbon::parse('2026-07-01'), $this->company);

        $resolver = app(\App\Services\ReportPeriodResolver::class);
        $ctx = $resolver->resolve($this->company->id, \Illuminate\Http\Request::create('/general-ledger/trial-balance'));
        $ledger = app(\App\Services\LedgerService::class);

        $debits = 0;
        $credits = 0;
        foreach ($ledger->getTrialBalance($this->company->id, $ctx->snapshotPeriodId) as $row) {
            $balance = (float) $row->closing_balance;
            if ($row->normal_balance === 'debit') {
                $debits += $balance;
            } else {
                $credits += $balance;
            }
        }

        $this->assertEqualsWithDelta($debits, $credits, 0.01, 'Trial balance must stay balanced.');
    }
}
