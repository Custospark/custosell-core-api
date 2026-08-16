<?php

namespace App\Services;

use App\Models\BillingPayment;
use App\Models\Business;
use App\Models\Payout;
use App\Models\User;
use App\Services\Currency\Contracts\CurrencyExchangeServiceInterface;
use Illuminate\Support\Facades\Log;

/**
 * Posts Custospark's own journal entries - the company books - for money
 * received (Custosell subscription payments) and money paid out (referral
 * rewards / commissions).
 *
 * The company ledger is a normal business record owned by the company owner
 * (default oscar@custospark.com, overridable via COMPANY_ACCOUNT_EMAIL). Every
 * entry is idempotent per reference so webhook retries never double-book.
 * Amounts are converted from the source currency into the company business's
 * currency using the exchange-rate service.
 */
class CompanyAccountingService
{
    public function __construct(
        protected JournalEntryService $journalEntryService,
        protected ChartOfAccountService $chartOfAccountService,
        protected AccountingPeriodService $accountingPeriodService,
        protected CurrencyExchangeServiceInterface $currencyExchange,
    ) {}

    /**
     * Resolve (and lazily ensure) the company's ledger business.
     *
     * The company business is identified by its owner's email. When it does not
     * exist yet we build a minimal placeholder business + its chart of accounts
     * + open periods so the books are always writable.
     */
    public function companyBusiness(): ?Business
    {
        $email = config('platform.company_accounting.owner_email');
        if (!$email) {
            return null;
        }

        $owner = User::query()
            ->where('email', $email)
            ->whereNull('deleted_at')
            ->first();

        if (!$owner) {
            Log::warning('[CompanyBooks] Company owner email not found; cannot journal', [
                'owner_email' => $email,
            ]);

            return null;
        }

        $business = Business::query()
            ->where('owner_id', $owner->id)
            ->orderByDesc('id')
            ->first();

        if (!$business) {
            $business = Business::create([
                'owner_id' => $owner->id,
                'name' => 'Custospark',
                'slug' => 'custospark',
                'email' => $email,
                'currency' => 'USD',
                'status' => 'active',
            ]);
        }

        $this->chartOfAccountService->seedDefaultTemplate($business->id);
        $this->accountingPeriodService->ensurePeriodsForBusiness($business->id);

        return $business->fresh();
    }

    /**
     * Convert an amount into the company business's currency. When the source
     * currency already equals the company currency, the amount is returned
     * unchanged (no conversion). When the exchange rate cannot be resolved the
     * conversion is skipped so a missing rate never blocks the books.
     */
    protected function convertToCompanyCurrency(Business $business, float $amount, string $from): float
    {
        $companyCurrency = $business->currency ?: 'USD';
        if (strtoupper($from) === strtoupper($companyCurrency)) {
            return round($amount, 2);
        }

        $converted = $this->currencyExchange->convert($amount, $companyCurrency, $from);

        return $converted !== null ? round($converted, 2) : round($amount, 2);
    }

    /**
     * Book a completed subscription payment into the company books:
     *   Dr Bank (amount received) / Cr Deferred Revenue (amount received).
     */
    public function accountForSubscriptionPayment(BillingPayment $payment): void
    {
        $business = $this->companyBusiness();
        if (!$business) {
            return;
        }

        $refType = config('platform.company_accounting.reference_types.subscription_payment');
        if ($this->journalEntryService->getEntryByReference($refType, $payment->id, $business->id)) {
            return;
        }

        $codes = config('platform.company_accounting.account_codes');
        $amount = $this->convertToCompanyCurrency($business, (float) $payment->amount, $payment->currency ?: 'USD');
        if ($amount <= 0) {
            return;
        }

        $date = $payment->paid_at
            ? $payment->paid_at->toDateString()
            : now()->toDateString();

        $paymentType = $payment->payment_type instanceof \App\Enums\Billing\PaymentType
            ? $payment->payment_type->value
            : $payment->payment_type;

        $description = sprintf(
            'Custosell subscription payment %s (%s) - %s',
            $payment->transaction_reference ?: $payment->id,
            $paymentType,
            $payment->subscription?->plan?->name ?? 'plan',
        );

        $lines = [
            [
                'account_code' => $codes['bank'],
                'debit' => $amount,
                'credit' => 0,
                'description' => $description,
            ],
            [
                'account_code' => $codes['deferred_revenue'],
                'debit' => 0,
                'credit' => $amount,
                'description' => $description,
            ],
        ];

        $this->journalEntryService->createAndPostEntry(
            $business->id,
            $date,
            $description,
            $lines,
            $refType,
            $payment->id,
            $business->owner_id,
        );

        Log::info('Company books: subscription payment journaled', [
            'payment_id' => $payment->id,
            'company_business_id' => $business->id,
            'amount' => $amount,
        ]);
    }

    /**
     * Book a completed payout into the company books:
     *   Dr Referral & Commission Expense (amount) / Cr Bank (amount).
     */
    public function accountForPayout(Payout $payout): void
    {
        $business = $this->companyBusiness();
        if (!$business) {
            return;
        }

        $refType = config('platform.company_accounting.reference_types.payout');
        if ($this->journalEntryService->getEntryByReference($refType, $payout->id, $business->id)) {
            return;
        }

        $codes = config('platform.company_accounting.account_codes');
        $amount = $this->convertToCompanyCurrency($business, (float) $payout->amount, $payout->currency ?: 'USD');
        if ($amount <= 0) {
            return;
        }

        $date = $payout->paid_at
            ? $payout->paid_at->toDateString()
            : now()->toDateString();

        $description = sprintf(
            'Custosell payout %d to %s',
            $payout->id,
            $payout->payable_type === User::class ? ($payout->payable?->name ?? 'user') : 'recipient',
        );

        $lines = [
            [
                'account_code' => $codes['referral_commission_expense'],
                'debit' => $amount,
                'credit' => 0,
                'description' => $description,
            ],
            [
                'account_code' => $codes['bank'],
                'debit' => 0,
                'credit' => $amount,
                'description' => $description,
            ],
        ];

        $this->journalEntryService->createAndPostEntry(
            $business->id,
            $date,
            $description,
            $lines,
            $refType,
            $payout->id,
            $business->owner_id,
        );

        Log::info('Company books: payout journaled', [
            'payout_id' => $payout->id,
            'company_business_id' => $business->id,
            'amount' => $amount,
        ]);
    }
}
