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

        // Idempotent: already posted → return existing.
        if ($payRun->status === 'posted') {
            return $payRun;
        }

        if ($payRun->status !== 'approved') {
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

        // Debit expense = gross + employer NSSF; credits = net + PAYE + employee NSSF + other + employer NSSF.
        $expenseDebit = round($totalGross + $totalNssfEr, 2);
        $intendedLines = [
            ['account_code' => '6101', 'debit' => $expenseDebit, 'credit' => 0, 'description' => 'Payroll expense (gross + employer NSSF)'],
            ['account_code' => '2103', 'debit' => 0, 'credit' => round($totalNet, 2), 'description' => 'Net pay payable'],
            ['account_code' => '2103', 'debit' => 0, 'credit' => round($totalPaye, 2), 'description' => 'PAYE payable'],
            ['account_code' => '2103', 'debit' => 0, 'credit' => round($totalNssfEmp + $totalNssfEr, 2), 'description' => 'NSSF payable (employee + employer)'],
        ];

        if ($totalOther > 0.009) {
            $intendedLines[] = [
                'account_code' => '2103',
                'debit' => 0,
                'credit' => round($totalOther, 2),
                'description' => 'Other payroll deductions',
            ];
        }

        // Drop zero-amount credit lines for balance cleanliness.
        $intendedLines = array_values(array_filter(
            $intendedLines,
            fn (array $l) => ($l['debit'] ?? 0) > 0.009 || ($l['credit'] ?? 0) > 0.009,
        ));

        $journalEntryId = null;
        $postingNote = null;

        try {
            $salaryAccount = ChartOfAccount::query()
                ->where('business_id', $businessId)
                ->where('code', '6101')
                ->first();
            $liabilityAccount = ChartOfAccount::query()
                ->where('business_id', $businessId)
                ->where('code', '2103')
                ->first();

            if (! $salaryAccount || ! $liabilityAccount) {
                throw new \RuntimeException('Required COA accounts 6101/2103 not found for this business.');
            }

            $entry = $this->journalEntries->createAndPostEntry(
                $businessId,
                $payRun->period_end->toDateString(),
                "Payroll {$payRun->period_start->toDateString()} – {$payRun->period_end->toDateString()}",
                $intendedLines,
                'hr_pay_run',
                $payRun->id,
                $actorUserId,
            );
            $journalEntryId = $entry->id;
            $postingNote = 'Posted to journal via JournalEntryService (6101 / 2103).';
        } catch (\Throwable $e) {
            Log::warning('HR pay run journal post skipped', [
                'pay_run_id' => $payRun->id,
                'error' => $e->getMessage(),
            ]);
            $postingNote = 'Journal entry not created: '.$e->getMessage()
                .'. Intended lines: '.json_encode($intendedLines)
                .'. Note: COA 2104–2106 are reserved (loans/dividends/deferred); payroll liabilities use 2103 Accrued Expenses.';
        }

        $payRun->update([
            'status' => 'posted',
            'posted_journal_entry_id' => $journalEntryId,
            'posted_at' => now(),
            'posting_note' => $postingNote,
        ]);

        HrPayslip::query()
            ->whereIn('pay_run_line_id', $lines->pluck('id'))
            ->whereNull('issued_at')
            ->update(['issued_at' => now()]);

        $this->audit->record($businessId, $actorUserId, 'pay_run.posted', 'hr_pay_run', $payRun->id, [
            'journal_entry_id' => $journalEntryId,
        ]);

        return $this->findPayRunOrFail($businessId, $payRun->id);
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
