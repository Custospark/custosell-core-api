<?php

declare(strict_types=1);

namespace App\Services\Hr;

use App\Models\ChartOfAccount;
use App\Models\Hr\HrEmployee;
use App\Models\Hr\HrEmployeeCompensation;
use App\Models\Hr\HrPayRun;
use App\Models\Hr\HrPayRunLine;
use App\Models\Hr\HrPayslip;
use App\Models\Hr\HrSalaryStructure;
use App\Models\Hr\HrStatutoryRateSet;
use App\Services\JournalEntryService;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class HrPayrollService
{
    public const PAY_RUN_STATUSES = ['draft', 'calculated', 'approved', 'posted', 'void'];

    /** Default Uganda PAYE monthly brackets (2024-ish). */
    public const DEFAULT_PAYE_BRACKETS = [
        ['up_to' => 235000, 'rate' => 0, 'base_tax' => 0],
        ['up_to' => 335000, 'rate' => 0.10, 'base_tax' => 0],
        ['up_to' => 410000, 'rate' => 0.20, 'base_tax' => 10000],
        ['up_to' => 10000000, 'rate' => 0.30, 'base_tax' => 25000],
        ['up_to' => null, 'rate' => 0.40, 'base_tax' => 2902500],
    ];

    public function __construct(
        protected HrEmployeeService $employees,
        protected HrAuditService $audit,
        protected JournalEntryService $journalEntries,
    ) {}

    public function listStructures(int $businessId): Collection
    {
        return HrSalaryStructure::query()
            ->where('business_id', $businessId)
            ->orderBy('name')
            ->get();
    }

    public function createStructure(int $businessId, array $data, ?int $actorUserId = null): HrSalaryStructure
    {
        $structure = HrSalaryStructure::create([
            'business_id' => $businessId,
            'name' => $data['name'],
            'currency' => $data['currency'] ?? 'UGX',
        ]);

        $this->audit->record($businessId, $actorUserId, 'salary_structure.created', 'hr_salary_structure', $structure->id);

        return $structure;
    }

    public function updateStructure(int $businessId, int $id, array $data, ?int $actorUserId = null): HrSalaryStructure
    {
        $structure = $this->findStructureOrFail($businessId, $id);
        $structure->fill(array_intersect_key($data, array_flip(['name', 'currency'])));
        $structure->save();

        $this->audit->record($businessId, $actorUserId, 'salary_structure.updated', 'hr_salary_structure', $structure->id);

        return $structure->fresh();
    }

    public function deleteStructure(int $businessId, int $id, ?int $actorUserId = null): void
    {
        $structure = $this->findStructureOrFail($businessId, $id);
        $structure->delete();

        $this->audit->record($businessId, $actorUserId, 'salary_structure.deleted', 'hr_salary_structure', $id);
    }

    public function findStructureOrFail(int $businessId, int $id): HrSalaryStructure
    {
        $structure = HrSalaryStructure::query()
            ->where('business_id', $businessId)
            ->whereKey($id)
            ->first();

        if (! $structure) {
            abort(404, 'Salary structure not found');
        }

        return $structure;
    }

    public function setCompensation(int $businessId, array $data, ?int $actorUserId = null): HrEmployeeCompensation
    {
        $this->employees->findOrFail($businessId, (int) $data['employee_id']);

        if (! empty($data['structure_id'])) {
            $this->findStructureOrFail($businessId, (int) $data['structure_id']);
        }

        $comp = HrEmployeeCompensation::create([
            'business_id' => $businessId,
            'employee_id' => $data['employee_id'],
            'structure_id' => $data['structure_id'] ?? null,
            'basic_salary' => $data['basic_salary'],
            'allowances_json' => $data['allowances_json'] ?? $data['allowances'] ?? [],
            'deductions_json' => $data['deductions_json'] ?? $data['deductions'] ?? [],
            'effective_from' => $data['effective_from'],
        ]);

        $this->audit->record($businessId, $actorUserId, 'compensation.set', 'hr_employee_compensation', $comp->id, [
            'employee_id' => $comp->employee_id,
            'basic_salary' => $comp->basic_salary,
        ]);

        return $comp->load(['employee:id,first_name,last_name,employee_number', 'structure:id,name,currency']);
    }

    public function listCompensations(int $businessId, ?int $employeeId = null): Collection
    {
        $query = HrEmployeeCompensation::query()
            ->where('business_id', $businessId)
            ->with(['employee:id,first_name,last_name,employee_number', 'structure:id,name'])
            ->orderByDesc('effective_from');

        if ($employeeId !== null) {
            $query->where('employee_id', $employeeId);
        }

        return $query->get();
    }

    public function findCompensationOrFail(int $businessId, int $id): HrEmployeeCompensation
    {
        $comp = HrEmployeeCompensation::query()
            ->where('business_id', $businessId)
            ->whereKey($id)
            ->first();

        if (! $comp) {
            abort(404, 'Compensation not found');
        }

        return $comp;
    }

    /**
     * Soft-delete compensation. Soft-deleted rows are excluded from latestCompensation
     * (and list queries) via SoftDeletes — historical pay-run lines are left intact.
     */
    public function deleteCompensation(int $businessId, int $id, ?int $actorUserId = null): void
    {
        $comp = $this->findCompensationOrFail($businessId, $id);
        $comp->delete();

        $this->audit->record($businessId, $actorUserId, 'compensation.deleted', 'hr_employee_compensation', $id, [
            'employee_id' => $comp->employee_id,
        ]);
    }

    public function createPayRun(int $businessId, array $data, ?int $actorUserId = null): HrPayRun
    {
        $start = Carbon::parse($data['period_start'])->toDateString();
        $end = Carbon::parse($data['period_end'])->toDateString();

        if ($end < $start) {
            throw ValidationException::withMessages([
                'period_end' => 'Period end must be on or after period start.',
            ]);
        }

        $payRun = HrPayRun::create([
            'business_id' => $businessId,
            'period_start' => $start,
            'period_end' => $end,
            'status' => 'draft',
        ]);

        $this->audit->record($businessId, $actorUserId, 'pay_run.created', 'hr_pay_run', $payRun->id);

        return $payRun;
    }

    public function listPayRuns(int $businessId): Collection
    {
        return HrPayRun::query()
            ->where('business_id', $businessId)
            ->withCount('lines')
            ->orderByDesc('period_start')
            ->get();
    }

    public function findPayRunOrFail(int $businessId, int $id): HrPayRun
    {
        $payRun = HrPayRun::query()
            ->where('business_id', $businessId)
            ->with(['lines.employee:id,first_name,last_name,employee_number', 'lines.payslip'])
            ->whereKey($id)
            ->first();

        if (! $payRun) {
            abort(404, 'Pay run not found');
        }

        return $payRun;
    }

    public function updatePayRun(int $businessId, int $id, array $data, ?int $actorUserId = null): HrPayRun
    {
        $payRun = $this->findPayRunOrFail($businessId, $id);

        if ($payRun->status !== 'draft') {
            throw ValidationException::withMessages([
                'status' => 'Only draft pay runs can have their period updated.',
            ]);
        }

        $start = array_key_exists('period_start', $data)
            ? Carbon::parse($data['period_start'])->toDateString()
            : $payRun->period_start->toDateString();
        $end = array_key_exists('period_end', $data)
            ? Carbon::parse($data['period_end'])->toDateString()
            : $payRun->period_end->toDateString();

        if ($end < $start) {
            throw ValidationException::withMessages([
                'period_end' => 'Period end must be on or after period start.',
            ]);
        }

        $payRun->period_start = $start;
        $payRun->period_end = $end;
        $payRun->save();

        $this->audit->record($businessId, $actorUserId, 'pay_run.updated', 'hr_pay_run', $payRun->id);

        return $this->findPayRunOrFail($businessId, $payRun->id);
    }

    /**
     * Soft-delete a draft or calculated pay run; hard-delete its lines and payslips.
     */
    public function deletePayRun(int $businessId, int $id, ?int $actorUserId = null): void
    {
        DB::transaction(function () use ($businessId, $id, $actorUserId) {
            $payRun = $this->findPayRunOrFail($businessId, $id);

            if (! in_array($payRun->status, ['draft', 'calculated'], true)) {
                throw ValidationException::withMessages([
                    'status' => 'Only draft or calculated pay runs can be deleted.',
                ]);
            }

            $lineIds = HrPayRunLine::query()
                ->where('pay_run_id', $payRun->id)
                ->pluck('id');

            if ($lineIds->isNotEmpty()) {
                HrPayslip::query()->whereIn('pay_run_line_id', $lineIds)->delete();
                HrPayRunLine::query()->whereIn('id', $lineIds)->delete();
            }

            $payRun->delete();

            $this->audit->record($businessId, $actorUserId, 'pay_run.deleted', 'hr_pay_run', $id);
        });
    }

    public function calculatePayRun(int $businessId, int $payRunId, ?int $actorUserId = null): HrPayRun
    {
        return DB::transaction(function () use ($businessId, $payRunId, $actorUserId) {
            $payRun = $this->findPayRunOrFail($businessId, $payRunId);

            if (! in_array($payRun->status, ['draft', 'calculated'], true)) {
                throw ValidationException::withMessages([
                    'status' => 'Only draft or calculated pay runs can be recalculated.',
                ]);
            }

            $rates = $this->resolveStatutoryRates($businessId, $payRun->period_end->toDateString());
            $employees = HrEmployee::query()
                ->where('business_id', $businessId)
                ->whereIn('status', ['active', 'on_leave', 'onboarding'])
                ->get();

            $existingLineIds = HrPayRunLine::query()
                ->where('pay_run_id', $payRun->id)
                ->pluck('id');

            if ($existingLineIds->isNotEmpty()) {
                HrPayslip::query()->whereIn('pay_run_line_id', $existingLineIds)->delete();
                HrPayRunLine::query()->whereIn('id', $existingLineIds)->delete();
            }

            foreach ($employees as $employee) {
                $comp = $this->latestCompensation($businessId, $employee->id, $payRun->period_end->toDateString());
                if (! $comp) {
                    continue;
                }

                $calc = $this->calculateEmployeePay($comp, $rates);

                $line = HrPayRunLine::create([
                    'business_id' => $businessId,
                    'pay_run_id' => $payRun->id,
                    'employee_id' => $employee->id,
                    'gross' => $calc['gross'],
                    'paye' => $calc['paye'],
                    'nssf_employee' => $calc['nssf_employee'],
                    'nssf_employer' => $calc['nssf_employer'],
                    'other_deductions' => $calc['other_deductions'],
                    'net' => $calc['net'],
                    'breakdown_json' => $calc['breakdown'],
                ]);

                HrPayslip::create([
                    'business_id' => $businessId,
                    'pay_run_line_id' => $line->id,
                    'employee_id' => $employee->id,
                    'payload_json' => [
                        'employee' => [
                            'id' => $employee->id,
                            'number' => $employee->employee_number,
                            'name' => $employee->full_name,
                        ],
                        'period' => [
                            'start' => $payRun->period_start->toDateString(),
                            'end' => $payRun->period_end->toDateString(),
                        ],
                        'earnings' => $calc['breakdown']['earnings'],
                        'deductions' => $calc['breakdown']['deductions'],
                        'employer_contributions' => [
                            'nssf_employer' => $calc['nssf_employer'],
                        ],
                        'totals' => [
                            'gross' => $calc['gross'],
                            'paye' => $calc['paye'],
                            'nssf_employee' => $calc['nssf_employee'],
                            'other_deductions' => $calc['other_deductions'],
                            'net' => $calc['net'],
                        ],
                    ],
                    'issued_at' => null,
                ]);
            }

            $payRun->status = 'calculated';
            $payRun->save();

            $this->audit->record($businessId, $actorUserId, 'pay_run.calculated', 'hr_pay_run', $payRun->id);

            return $this->findPayRunOrFail($businessId, $payRun->id);
        });
    }

    public function approvePayRun(int $businessId, int $payRunId, ?int $actorUserId = null): HrPayRun
    {
        $payRun = $this->findPayRunOrFail($businessId, $payRunId);

        if ($payRun->status !== 'calculated') {
            throw ValidationException::withMessages([
                'status' => 'Only calculated pay runs can be approved.',
            ]);
        }

        $payRun->status = 'approved';
        $payRun->save();

        $this->audit->record($businessId, $actorUserId, 'pay_run.approved', 'hr_pay_run', $payRun->id);

        return $payRun->fresh(['lines.employee', 'lines.payslip']);
    }

    public function postPayRun(int $businessId, int $payRunId, ?int $actorUserId = null): HrPayRun
    {
        $payRun = $this->findPayRunOrFail($businessId, $payRunId);

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
                "Payroll {$payRun->period_start->toDateString()} – {$payRun->period_end->toDateString()}",
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

        return $this->findPayRunOrFail($businessId, $payRun->id);
    }

    /**
     * Pay net salaries (and other deductions held in salaries payable) from bank/cash.
     *
     * @param  array{funding_account_code?: string}  $options
     */
    public function settlePayRun(int $businessId, int $payRunId, array $options = [], ?int $actorUserId = null): HrPayRun
    {
        $payRun = $this->findPayRunOrFail($businessId, $payRunId);

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
                'lines' => 'Nothing to settle — net pay is zero.',
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
                "Payroll settlement {$payRun->period_start->toDateString()} – {$payRun->period_end->toDateString()}",
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

        return $this->findPayRunOrFail($businessId, $payRun->id);
    }

    /**
     * Remit PAYE + NSSF liabilities from bank/cash.
     *
     * @param  array{funding_account_code?: string}  $options
     */
    public function remitStatutory(int $businessId, int $payRunId, array $options = [], ?int $actorUserId = null): HrPayRun
    {
        $payRun = $this->findPayRunOrFail($businessId, $payRunId);

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
                'lines' => 'Nothing to remit — PAYE and NSSF are zero.',
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
                "Payroll statutory remittance {$payRun->period_start->toDateString()} – {$payRun->period_end->toDateString()}",
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

        return $this->findPayRunOrFail($businessId, $payRun->id);
    }

    public function voidPayRun(int $businessId, int $payRunId, ?int $actorUserId = null): HrPayRun
    {
        $payRun = $this->findPayRunOrFail($businessId, $payRunId);

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

            return $this->findPayRunOrFail($businessId, $payRun->id);
        }

        if ($payRun->status === 'posted' && ! $payRun->posted_journal_entry_id) {
            // Legacy soft-fail: void without reversing.
            $payRun->update([
                'status' => 'void',
                'voided_at' => now(),
                'posting_note' => trim(($payRun->posting_note ? $payRun->posting_note.' ' : '').'Voided (no accrual journal existed).'),
            ]);
            $this->audit->record($businessId, $actorUserId, 'pay_run.voided', 'hr_pay_run', $payRun->id);

            return $this->findPayRunOrFail($businessId, $payRun->id);
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

        return $this->findPayRunOrFail($businessId, $payRun->id);
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

    /**
     * Progressive Uganda PAYE on monthly taxable pay (after employee NSSF).
     *
     * @param  list<array{up_to: int|float|null, rate: float, base_tax: float|int}>  $brackets
     */
    public function calculatePaye(float $taxable, array $brackets): float
    {
        if ($taxable <= 0) {
            return 0.0;
        }

        $prevUpTo = 0.0;
        $tax = 0.0;

        foreach ($brackets as $bracket) {
            $upTo = $bracket['up_to'];
            $rate = (float) $bracket['rate'];
            $baseTax = (float) ($bracket['base_tax'] ?? 0);

            if ($upTo === null) {
                if ($taxable > $prevUpTo) {
                    $tax = $baseTax + (($taxable - $prevUpTo) * $rate);
                }

                break;
            }

            $upTo = (float) $upTo;

            if ($taxable <= $upTo) {
                $tax = $baseTax + (max(0, $taxable - $prevUpTo) * $rate);
                break;
            }

            $prevUpTo = $upTo;
        }

        return round($tax, 2);
    }

    public function resolveStatutoryRates(int $businessId, string $asOfDate): HrStatutoryRateSet
    {
        $businessRate = HrStatutoryRateSet::query()
            ->where('business_id', $businessId)
            ->where('country', 'UG')
            ->where('effective_from', '<=', $asOfDate)
            ->orderByDesc('effective_from')
            ->first();

        if ($businessRate) {
            return $businessRate;
        }

        $global = HrStatutoryRateSet::query()
            ->whereNull('business_id')
            ->where('country', 'UG')
            ->where('effective_from', '<=', $asOfDate)
            ->orderByDesc('effective_from')
            ->first();

        if ($global) {
            return $global;
        }

        // In-memory fallback matching seeded defaults.
        $fallback = new HrStatutoryRateSet([
            'country' => 'UG',
            'effective_from' => '2024-07-01',
            'paye_brackets_json' => self::DEFAULT_PAYE_BRACKETS,
            'nssf_employee_rate' => 0.05,
            'nssf_employer_rate' => 0.10,
            'notes' => 'In-code Uganda defaults',
        ]);

        return $fallback;
    }

    /**
     * @return array{
     *   gross: float,
     *   paye: float,
     *   nssf_employee: float,
     *   nssf_employer: float,
     *   other_deductions: float,
     *   net: float,
     *   breakdown: array<string, mixed>
     * }
     */
    public function calculateEmployeePay(HrEmployeeCompensation $comp, HrStatutoryRateSet $rates): array
    {
        $allowances = is_array($comp->allowances_json) ? $comp->allowances_json : [];
        $deductions = is_array($comp->deductions_json) ? $comp->deductions_json : [];

        $allowanceTotal = $this->sumNamedAmounts($allowances);
        $otherDeductions = $this->sumNamedAmounts($deductions);
        $basic = (float) $comp->basic_salary;
        $gross = round($basic + $allowanceTotal, 2);

        $nssfEmployeeRate = (float) $rates->nssf_employee_rate;
        $nssfEmployerRate = (float) $rates->nssf_employer_rate;

        // Pensionable = basic (common UG practice); no hard cap applied by default.
        $pensionable = $basic;
        $nssfEmployee = round($pensionable * $nssfEmployeeRate, 2);
        $nssfEmployer = round($pensionable * $nssfEmployerRate, 2);

        $taxable = max(0, $gross - $nssfEmployee);
        $brackets = is_array($rates->paye_brackets_json) && $rates->paye_brackets_json !== []
            ? $rates->paye_brackets_json
            : self::DEFAULT_PAYE_BRACKETS;
        $paye = $this->calculatePaye($taxable, $brackets);

        $net = round($gross - $paye - $nssfEmployee - $otherDeductions, 2);

        return [
            'gross' => $gross,
            'paye' => $paye,
            'nssf_employee' => $nssfEmployee,
            'nssf_employer' => $nssfEmployer,
            'other_deductions' => round($otherDeductions, 2),
            'net' => $net,
            'breakdown' => [
                'earnings' => [
                    'basic_salary' => $basic,
                    'allowances' => $allowances,
                    'allowance_total' => $allowanceTotal,
                    'gross' => $gross,
                ],
                'deductions' => [
                    'nssf_employee' => $nssfEmployee,
                    'paye' => $paye,
                    'other' => $deductions,
                    'other_total' => $otherDeductions,
                ],
                'taxable' => $taxable,
                'pensionable' => $pensionable,
            ],
        ];
    }

    /**
     * Latest non-soft-deleted compensation on or before $asOfDate (SoftDeletes excludes deleted rows).
     */
    protected function latestCompensation(int $businessId, int $employeeId, string $asOfDate): ?HrEmployeeCompensation
    {
        return HrEmployeeCompensation::query()
            ->where('business_id', $businessId)
            ->where('employee_id', $employeeId)
            ->where('effective_from', '<=', $asOfDate)
            ->orderByDesc('effective_from')
            ->first();
    }

    /** @param  array<int|string, mixed>  $items */
    protected function sumNamedAmounts(array $items): float
    {
        $total = 0.0;

        foreach ($items as $item) {
            if (is_array($item)) {
                $total += (float) ($item['amount'] ?? $item['value'] ?? 0);
            } elseif (is_numeric($item)) {
                $total += (float) $item;
            }
        }

        return $total;
    }
}
