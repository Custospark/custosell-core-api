<?php

declare(strict_types=1);

namespace App\Services\Hr;

use App\Models\ChartOfAccount;
use App\Models\Hr\HrPayRun;
use App\Models\Hr\HrPayRunLine;
use App\Models\Hr\HrPayslip;
use App\Services\JournalEntryService;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class HrPayrollJournalService
{
    public function __construct(
        protected JournalEntryService $journalEntries,
        protected HrAuditService $audit,
    ) {}

    public function postPayRun(HrPayRun $payRun, int $businessId, ?int $actorUserId = null): HrPayRun
    {
        // Idempotent: fully posted with journal → return.
        if ($payRun->status === 'posted' && $payRun->posted_journal_entry_id) {
            return $payRun;
        }

        // Legacy soft-fail retry: posted without journal may re-attempt.
        $isLegacyRetry = $payRun->status === 'posted' && ! $payRun->posted_journal_entry_id;

        if (! $isLegacyRetry && $payRun->status !== 'approved') {
            throw ValidationException::withMessages([
                'status' => 'Only approved pay runs can be posted.',
            ]);
        }

        $lines = $payRun->lines;
        if ($lines->isEmpty()) {
            throw ValidationException::withMessages([
                'lines' => 'Pay run has no lines to post.',
            ]);
        }

        $totalGross = (float) $lines->sum('gross');
        $totalPaye = (float) $lines->sum('paye');
        $totalNssfEmp = (float) $lines->sum('nssf_employee');
        $totalNssfEr = (float) $lines->sum('nssf_employer');
        $totalOther = (float) $lines->sum('other_deductions');
        $totalNet = (float) $lines->sum('net');

        $expenseCode = (string) config('accounting.default_account_codes.salaries_expense', '6101');
        $salariesPayable = (string) config('accounting.default_account_codes.salaries_payable', '2110');
        $payePayable = (string) config('accounting.default_account_codes.paye_payable', '2111');
        $nssfPayable = (string) config('accounting.default_account_codes.nssf_payable', '2112');

        // Debit expense = gross + employer NSSF; credits = net + other + PAYE + NSSF (ee+er).
        $expenseDebit = round($totalGross + $totalNssfEr, 2);
        $netCredit = round($totalNet + $totalOther, 2);
        $intendedLines = [
            ['account_code' => $expenseCode, 'debit' => $expenseDebit, 'credit' => 0, 'description' => 'Payroll expense (gross + employer NSSF)'],
            ['account_code' => $salariesPayable, 'debit' => 0, 'credit' => $netCredit, 'description' => 'Salaries payable (net + other deductions)'],
            ['account_code' => $payePayable, 'debit' => 0, 'credit' => round($totalPaye, 2), 'description' => 'PAYE payable'],
            ['account_code' => $nssfPayable, 'debit' => 0, 'credit' => round($totalNssfEmp + $totalNssfEr, 2), 'description' => 'NSSF payable (employee + employer)'],
        ];

        $intendedLines = array_values(array_filter(
            $intendedLines,
            fn (array $l) => ($l['debit'] ?? 0) > 0.009 || ($l['credit'] ?? 0) > 0.009,
        ));

        try {
            $this->ensurePayrollAccounts($businessId);

            $entry = $this->journalEntries->createAndPostEntry(
                $businessId,
                $payRun->period_end->toDateString(),
                "Payroll {$payRun->period_start->toDateString()} - {$payRun->period_end->toDateString()}",
                $intendedLines,
                'hr_pay_run',
                $payRun->id,
                $actorUserId,
            );
        } catch (\Throwable $e) {
            Log::warning('HR pay run journal post failed', [
                'pay_run_id' => $payRun->id,
                'error' => $e->getMessage(),
            ]);

            $note = ($isLegacyRetry ? 'Retry failed: ' : 'Journal entry not created: ')
                .$e->getMessage()
                .'. Intended lines: '.json_encode($intendedLines);

            HrPayRun::query()->whereKey($payRun->id)->update([
                'posting_note' => $note,
            ]);

            throw ValidationException::withMessages([
                'accounting' => 'Could not post payroll to accounting: '.$e->getMessage()
                    .'. Ensure accounts '.$expenseCode.'/'.$salariesPayable.'/'.$payePayable.'/'.$nssfPayable
                    .' exist and an open accounting period covers '.$payRun->period_end->toDateString().'.',
            ]);
        }

        $payRun->update([
            'status' => 'posted',
            'posted_journal_entry_id' => $entry->id,
            'posted_at' => now(),
            'posting_note' => "Posted to journal #{$entry->id} ({$expenseCode} / {$salariesPayable} / {$payePayable} / {$nssfPayable}).",
        ]);

        HrPayslip::query()
            ->whereIn('pay_run_line_id', $lines->pluck('id'))
            ->whereNull('issued_at')
            ->update(['issued_at' => now()]);

        $this->audit->record($businessId, $actorUserId, 'pay_run.posted', 'hr_pay_run', $payRun->id, [
            'journal_entry_id' => $entry->id,
        ]);

        return $payRun->fresh(['lines.employee', 'lines.payslip']);
    }

    /**
     * Pay net salaries (and other deductions held in salaries payable) from bank/cash.
     *
     * @param  array{funding_account_code?: string}  $options
     */
    public function settlePayRun(HrPayRun $payRun, int $businessId, array $options = [], ?int $actorUserId = null): HrPayRun
    {
        if ($payRun->status === 'posted' && $payRun->settlement_journal_entry_id && $payRun->net_settled_at) {
            return $payRun;
        }

        if ($payRun->status !== 'posted' || ! $payRun->posted_journal_entry_id) {
            throw ValidationException::withMessages([
                'status' => 'Only posted pay runs with an accrual journal can be settled.',
            ]);
        }

        if ($payRun->voided_at) {
            throw ValidationException::withMessages([
                'status' => 'Voided pay runs cannot be settled.',
            ]);
        }

        $lines = $payRun->lines;
        $totalNet = round((float) $lines->sum('net') + (float) $lines->sum('other_deductions'), 2);
        if ($totalNet <= 0.009) {
            throw ValidationException::withMessages([
                'lines' => 'Nothing to settle - net pay is zero.',
            ]);
        }

        $funding = $this->resolveFundingAccountCode($options['funding_account_code'] ?? null);
        $salariesPayable = (string) config('accounting.default_account_codes.salaries_payable', '2110');

        $journalLines = [
            ['account_code' => $salariesPayable, 'debit' => $totalNet, 'credit' => 0, 'description' => 'Clear salaries payable'],
            ['account_code' => $funding, 'debit' => 0, 'credit' => $totalNet, 'description' => 'Net payroll payment'],
        ];

        try {
            $this->ensurePayrollAccounts($businessId);
            $entry = $this->journalEntries->createAndPostEntry(
                $businessId,
                now()->toDateString(),
                "Payroll settlement {$payRun->period_start->toDateString()} - {$payRun->period_end->toDateString()}",
                $journalLines,
                'hr_pay_run_settlement',
                $payRun->id,
                $actorUserId,
            );
        } catch (\Throwable $e) {
            throw ValidationException::withMessages([
                'accounting' => 'Could not settle payroll: '.$e->getMessage(),
            ]);
        }

        $payRun->update([
            'settlement_journal_entry_id' => $entry->id,
            'net_settled_at' => now(),
            'posting_note' => trim(($payRun->posting_note ? $payRun->posting_note.' ' : '')."Net settled via journal #{$entry->id}."),
        ]);

        $this->audit->record($businessId, $actorUserId, 'pay_run.settled', 'hr_pay_run', $payRun->id, [
            'journal_entry_id' => $entry->id,
            'funding_account_code' => $funding,
        ]);

        return $payRun->fresh(['lines.employee', 'lines.payslip']);
    }

    /**
     * Remit PAYE + NSSF liabilities from bank/cash.
     *
     * @param  array{funding_account_code?: string}  $options
     */
    public function remitStatutory(HrPayRun $payRun, int $businessId, array $options = [], ?int $actorUserId = null): HrPayRun
    {
        if ($payRun->status === 'posted' && $payRun->statutory_journal_entry_id && $payRun->statutory_remitted_at) {
            return $payRun;
        }

        if ($payRun->status !== 'posted' || ! $payRun->posted_journal_entry_id) {
            throw ValidationException::withMessages([
                'status' => 'Only posted pay runs with an accrual journal can remit statutory amounts.',
            ]);
        }

        if ($payRun->voided_at) {
            throw ValidationException::withMessages([
                'status' => 'Voided pay runs cannot remit statutory amounts.',
            ]);
        }

        $lines = $payRun->lines;
        $totalPaye = round((float) $lines->sum('paye'), 2);
        $totalNssf = round((float) $lines->sum('nssf_employee') + (float) $lines->sum('nssf_employer'), 2);
        $total = round($totalPaye + $totalNssf, 2);

        if ($total <= 0.009) {
            throw ValidationException::withMessages([
                'lines' => 'Nothing to remit - PAYE and NSSF are zero.',
            ]);
        }

        $funding = $this->resolveFundingAccountCode($options['funding_account_code'] ?? null);
        $payePayable = (string) config('accounting.default_account_codes.paye_payable', '2111');
        $nssfPayable = (string) config('accounting.default_account_codes.nssf_payable', '2112');

        $journalLines = [];
        if ($totalPaye > 0.009) {
            $journalLines[] = ['account_code' => $payePayable, 'debit' => $totalPaye, 'credit' => 0, 'description' => 'Clear PAYE payable'];
        }
        if ($totalNssf > 0.009) {
            $journalLines[] = ['account_code' => $nssfPayable, 'debit' => $totalNssf, 'credit' => 0, 'description' => 'Clear NSSF payable'];
        }
        $journalLines[] = ['account_code' => $funding, 'debit' => 0, 'credit' => $total, 'description' => 'PAYE/NSSF remittance'];

        try {
            $this->ensurePayrollAccounts($businessId);
            $entry = $this->journalEntries->createAndPostEntry(
                $businessId,
                now()->toDateString(),
                "Payroll statutory remittance {$payRun->period_start->toDateString()} - {$payRun->period_end->toDateString()}",
                $journalLines,
                'hr_pay_run_statutory',
                $payRun->id,
                $actorUserId,
            );
        } catch (\Throwable $e) {
            throw ValidationException::withMessages([
                'accounting' => 'Could not remit statutory payroll: '.$e->getMessage(),
            ]);
        }

        $payRun->update([
            'statutory_journal_entry_id' => $entry->id,
            'statutory_remitted_at' => now(),
            'posting_note' => trim(($payRun->posting_note ? $payRun->posting_note.' ' : '')."Statutory remitted via journal #{$entry->id}."),
        ]);

        $this->audit->record($businessId, $actorUserId, 'pay_run.statutory_remitted', 'hr_pay_run', $payRun->id, [
            'journal_entry_id' => $entry->id,
            'funding_account_code' => $funding,
        ]);

        return $payRun->fresh(['lines.employee', 'lines.payslip']);
    }

    public function voidPayRun(HrPayRun $payRun, int $businessId, ?int $actorUserId = null): HrPayRun
    {
        if ($payRun->status === 'void') {
            return $payRun;
        }

        if (! in_array($payRun->status, ['posted', 'approved'], true)) {
            throw ValidationException::withMessages([
                'status' => 'Only approved or posted pay runs can be voided.',
            ]);
        }

        // Approved with no journal: just mark void.
        if ($payRun->status === 'approved' && ! $payRun->posted_journal_entry_id) {
            $payRun->update([
                'status' => 'void',
                'voided_at' => now(),
                'posting_note' => trim(($payRun->posting_note ? $payRun->posting_note.' ' : '').'Voided before accounting post.'),
            ]);
            $this->audit->record($businessId, $actorUserId, 'pay_run.voided', 'hr_pay_run', $payRun->id);

            return $payRun->fresh(['lines.employee', 'lines.payslip']);
        }

        if ($payRun->status === 'posted' && ! $payRun->posted_journal_entry_id) {
            // Legacy soft-fail: void without reversing.
            $payRun->update([
                'status' => 'void',
                'voided_at' => now(),
                'posting_note' => trim(($payRun->posting_note ? $payRun->posting_note.' ' : '').'Voided (no accrual journal existed).'),
            ]);
            $this->audit->record($businessId, $actorUserId, 'pay_run.voided', 'hr_pay_run', $payRun->id);

            return $payRun->fresh(['lines.employee', 'lines.payslip']);
        }

        $journalIds = array_values(array_filter([
            $payRun->settlement_journal_entry_id,
            $payRun->statutory_journal_entry_id,
            $payRun->posted_journal_entry_id,
        ]));

        try {
            foreach ($journalIds as $journalId) {
                $this->journalEntries->createReversingEntry((int) $journalId, $actorUserId);
            }
        } catch (\Throwable $e) {
            throw ValidationException::withMessages([
                'accounting' => 'Could not void payroll in accounting: '.$e->getMessage()
                    .'. Ensure the accounting period is open for reversing entries.',
            ]);
        }

        $payRun->update([
            'status' => 'void',
            'voided_at' => now(),
            'posting_note' => trim(($payRun->posting_note ? $payRun->posting_note.' ' : '').'Voided; linked journals reversed.'),
        ]);

        $this->audit->record($businessId, $actorUserId, 'pay_run.voided', 'hr_pay_run', $payRun->id, [
            'reversed_journal_ids' => $journalIds,
        ]);

        return $payRun->fresh(['lines.employee', 'lines.payslip']);
    }

    /**
     * Ensure payroll COA codes exist for the business (idempotent).
     */
    public function ensurePayrollAccounts(int $businessId): void
    {
        $codes = [
            (string) config('accounting.default_account_codes.salaries_expense', '6101') => [
                'name' => 'Salaries & Wages',
                'parent_code' => '6100',
                'normal_balance' => 'debit',
            ],
            (string) config('accounting.default_account_codes.salaries_payable', '2110') => [
                'name' => 'Salaries Payable',
                'parent_code' => '2100',
                'normal_balance' => 'credit',
            ],
            (string) config('accounting.default_account_codes.paye_payable', '2111') => [
                'name' => 'PAYE Payable',
                'parent_code' => '2100',
                'normal_balance' => 'credit',
            ],
            (string) config('accounting.default_account_codes.nssf_payable', '2112') => [
                'name' => 'NSSF Payable',
                'parent_code' => '2100',
                'normal_balance' => 'credit',
            ],
            (string) config('accounting.default_account_codes.bank', '1102') => [
                'name' => 'Bank',
                'parent_code' => '1100',
                'normal_balance' => 'debit',
            ],
            (string) config('accounting.default_account_codes.cash', '1101') => [
                'name' => 'Cash',
                'parent_code' => '1100',
                'normal_balance' => 'debit',
            ],
        ];

        $existing = ChartOfAccount::query()
            ->where('business_id', $businessId)
            ->get()
            ->keyBy('code');

        foreach ($codes as $code => $meta) {
            if ($existing->has($code)) {
                continue;
            }

            $parent = $existing->get($meta['parent_code']);
            $typeSibling = $existing->first(fn (ChartOfAccount $a) => $a->normal_balance === $meta['normal_balance']);
            if (! $parent && ! $typeSibling) {
                throw new \RuntimeException("Cannot ensure payroll account {$code}: chart of accounts is not seeded for this business.");
            }

            $created = ChartOfAccount::create([
                'business_id' => $businessId,
                'code' => $code,
                'name' => $meta['name'],
                'parent_id' => $parent?->id,
                'type_id' => $parent?->type_id ?? $typeSibling->type_id,
                'normal_balance' => $meta['normal_balance'],
                'is_active' => true,
                'is_system' => true,
            ]);
            $existing->put($code, $created);
        }
    }

    protected function resolveFundingAccountCode(?string $requested): string
    {
        $bank = (string) config('accounting.default_account_codes.bank', '1102');
        $cash = (string) config('accounting.default_account_codes.cash', '1101');
        if ($requested === null || $requested === '') {
            return $bank;
        }
        if (! in_array($requested, [$bank, $cash], true)) {
            throw ValidationException::withMessages([
                'funding_account_code' => "Funding account must be {$bank} (Bank) or {$cash} (Cash).",
            ]);
        }

        return $requested;
    }
}
