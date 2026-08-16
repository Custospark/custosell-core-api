<?php

namespace App\Services;

use App\Models\BillingPayment;
use App\Models\Business;
use App\Models\JournalEntry;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * Recognizes deferred subscription revenue into earned software revenue.
 *
 * When a subscription payment completes we book the full amount as deferred
 * revenue (Dr Bank / Cr Deferred Revenue). Over the subscription's coverage
 * period that liability is earned and must move to Software Revenue:
 *
 *   Dr Deferred Revenue / Cr Software Revenue
 *
 * Recognition is performed in monthly buckets pro-rated by coverage days so a
 * partial first/last month is earned proportionally. Idempotency is enforced
 * by a cumulative delta: the target for all fully-elapsed months minus what has
 * already been recognized. Re-running for the same date posts nothing, so the
 * scheduler can run daily safely.
 */
class SubscriptionRevenueRecognitionService
{
    private const REFERENCE_TYPE = 'platform_revenue_recognition';

    public function __construct(
        protected JournalEntryService $journalEntryService,
        protected CompanyAccountingService $companyAccounting,
    ) {}

    /**
     * Recognize earned revenue for every completed subscription payment that has
     * a deferred-revenue entry, as of the given date (defaults to today).
     *
     * @return array{recognized: int, payments: int, total_amount: float}
     */
    public function recognizeDue(?\Carbon\Carbon $asOf = null): array
    {
        $asOf = ($asOf ?? now())->startOfDay();
        $company = $this->companyAccounting->companyBusiness();

        if (!$company) {
            return ['recognized' => 0, 'payments' => 0, 'total_amount' => 0.0];
        }

        // The deferred-revenue entries are the source of truth: every payment
        // journaled into the company books gets recognized, regardless of which
        // business paid (the company itself can be a customer in some setups).
        $deferred = JournalEntry::query()
            ->where('business_id', $company->id)
            ->where('reference_type', 'platform_subscription_payment')
            ->whereNull('deleted_at')
            ->pluck('reference_id')
            ->filter()
            ->unique()
            ->values()
            ->all();

        $payments = BillingPayment::query()
            ->whereIn('id', $deferred)
            ->get();

        $recognized = 0;
        $totalAmount = 0.0;

        foreach ($payments as $payment) {
            $amount = $this->recognizeForPayment($payment, $asOf, $company);
            if ($amount > 0) {
                $recognized++;
                $totalAmount += $amount;
            }
        }

        return [
            'recognized' => $recognized,
            'payments' => $payments->count(),
            'total_amount' => round($totalAmount, 2),
        ];
    }

    /**
     * Recognize the earned (elapsed) portion of one payment as of $asOf.
     * Returns the amount posted in company currency, or 0 when nothing is due.
     */
    public function recognizeForPayment(BillingPayment $payment, \Carbon\Carbon $asOf, Business $company): float
    {
        $deferredEntry = $this->journalEntryService->getEntryByReference(
            'platform_subscription_payment',
            $payment->id,
            $company->id,
        );

        if (!$deferredEntry) {
            return 0.0;
        }

        $deferredAmount = $this->deferredAmount($deferredEntry);
        if ($deferredAmount <= 0) {
            return 0.0;
        }

        $coverage = $this->coveragePeriod($payment);
        if (!$coverage) {
            return 0.0;
        }

        [$coverageStart, $coverageEnd] = $coverage;

        // Nothing earned yet if the coverage period hasn't begun.
        if ($asOf->lte($coverageStart)) {
            return 0.0;
        }

        $totalCoverageDays = max(1, (int) $coverageStart->diffInDays($coverageEnd) + 1);

        // Target = sum of fully-elapsed calendar-month buckets.
        $target = 0.0;
        $cursor = $coverageStart->copy()->startOfMonth();
        $coverageEndOfMonth = $coverageEnd->copy()->startOfMonth();

        while ($cursor->lte($coverageEndOfMonth)) {
            $monthStart = $cursor->copy();
            $monthEnd = $cursor->copy()->endOfMonth()->min($coverageEnd);

            // A month is earned only when it has fully elapsed as of $asOf.
            if ($asOf->gt($monthEnd)) {
                $overlapStart = $monthStart->copy()->max($coverageStart);
                $overlapDays = (int) $overlapStart->diffInDays($monthEnd) + 1;
                $target += $deferredAmount * ($overlapDays / $totalCoverageDays);
            }

            $cursor->addMonth();
        }

        $target = round($target, 2);

        $recognizedSoFar = $this->recognizedSoFar($company->id, $payment->id);
        $delta = round($target - $recognizedSoFar, 2);

        if ($delta < 0.01) {
            return 0.0;
        }

        $date = $asOf->toDateString();
        $description = sprintf(
            'Revenue recognition - subscription payment %s (through %s)',
            $payment->transaction_reference ?: $payment->id,
            $asOf->format('M Y'),
        );

        $codes = config('platform.company_accounting.account_codes');

        $entry = $this->journalEntryService->createAndPostEntry(
            $company->id,
            $date,
            $description,
            [
                [
                    'account_code' => $codes['deferred_revenue'],
                    'debit' => $delta,
                    'credit' => 0,
                    'description' => $description,
                ],
                [
                    'account_code' => $codes['software_revenue'],
                    'debit' => 0,
                    'credit' => $delta,
                    'description' => $description,
                ],
            ],
            self::REFERENCE_TYPE,
            $payment->id,
            $company->owner_id,
        );

        Log::info('Company books: deferred revenue recognized', [
            'payment_id' => $payment->id,
            'company_business_id' => $company->id,
            'amount' => $delta,
            'recognized_through' => $date,
            'entry_id' => $entry->id,
        ]);

        return $delta;
    }

    /**
     * Total deferred revenue booked for this payment (sum of Deferred Revenue
     * credits across the deferred journal entry's lines).
     */
    protected function deferredAmount(JournalEntry $entry): float
    {
        $deferredCode = config('platform.company_accounting.account_codes.deferred_revenue');

        return (float) $entry->lines
            ->filter(fn ($line) => $line->chartOfAccount && $line->chartOfAccount->code === $deferredCode)
            ->sum('credit_amount');
    }

    /**
     * Coverage period for a payment: starts at paid date, lasts the paid-for
     * duration (topup months, else billing cycle).
     *
     * @return array{\Carbon\Carbon, \Carbon\Carbon}|null
     */
    protected function coveragePeriod(BillingPayment $payment): ?array
    {
        $start = $payment->paid_at?->copy()->startOfDay()
            ?? $payment->created_at?->copy()->startOfDay();

        if (!$start) {
            return null;
        }

        $type = $payment->payment_type instanceof \App\Enums\Billing\PaymentType
            ? $payment->payment_type->value
            : $payment->payment_type;

        $months = null;
        if ($type === 'topup') {
            $months = (int) ($payment->metadata['topup_months'] ?? 0);
        }

        if (!$months || $months < 1) {
            $cycle = $payment->subscription?->billing_cycle ?? 'monthly';
            $months = $cycle === 'yearly' ? 12 : 1;
        }

        $end = $start->copy()->addMonths($months)->subDay();

        return [$start, $end];
    }

    /**
     * Cumulative revenue already recognized for a payment (sum of Software
     * Revenue credits across prior recognition entries).
     */
    protected function recognizedSoFar(int $companyBusinessId, int $paymentId): float
    {
        $softwareCode = config('platform.company_accounting.account_codes.software_revenue');

        $entries = JournalEntry::query()
            ->where('business_id', $companyBusinessId)
            ->where('reference_type', self::REFERENCE_TYPE)
            ->where('reference_id', $paymentId)
            ->whereNull('deleted_at')
            ->with('lines.chartOfAccount')
            ->get();

        return (float) $entries
            ->flatMap(fn ($entry) => $entry->lines)
            ->filter(fn ($line) => $line->chartOfAccount && $line->chartOfAccount->code === $softwareCode)
            ->sum('credit_amount');
    }
}
