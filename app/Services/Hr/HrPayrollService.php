<?php

declare(strict_types=1);

namespace App\Services\Hr;

use App\Models\Hr\HrEmployee;
use App\Models\Hr\HrEmployeeCompensation;
use App\Models\Hr\HrPayRun;
use App\Models\Hr\HrPayRunLine;
use App\Models\Hr\HrPayslip;
use App\Models\Hr\HrSalaryStructure;
use App\Models\Hr\HrStatutoryRateSet;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class HrPayrollService
{
    public const PAY_RUN_STATUSES = ['draft', 'calculated', 'approved', 'posted', 'void'];

    public function __construct(
        protected HrEmployeeService $employees,
        protected HrAuditService $audit,
        protected HrPayrollCalculator $calculator,
        protected HrPayrollJournalService $journalService,
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
     * (and list queries) via SoftDeletes - historical pay-run lines are left intact.
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

            $rates = $this->calculator->resolveStatutoryRates($businessId, $payRun->period_end->toDateString());
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
                $comp = $this->calculator->latestCompensation($businessId, $employee->id, $payRun->period_end->toDateString());
                if (! $comp) {
                    continue;
                }

                $calc = $this->calculator->calculateEmployeePay($comp, $rates);

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

        return $this->journalService->postPayRun($payRun, $businessId, $actorUserId);
    }

    public function settlePayRun(int $businessId, int $payRunId, array $options = [], ?int $actorUserId = null): HrPayRun
    {
        $payRun = $this->findPayRunOrFail($businessId, $payRunId);

        return $this->journalService->settlePayRun($payRun, $businessId, $options, $actorUserId);
    }

    public function remitStatutory(int $businessId, int $payRunId, array $options = [], ?int $actorUserId = null): HrPayRun
    {
        $payRun = $this->findPayRunOrFail($businessId, $payRunId);

        return $this->journalService->remitStatutory($payRun, $businessId, $options, $actorUserId);
    }

    public function voidPayRun(int $businessId, int $payRunId, ?int $actorUserId = null): HrPayRun
    {
        $payRun = $this->findPayRunOrFail($businessId, $payRunId);

        return $this->journalService->voidPayRun($payRun, $businessId, $actorUserId);
    }

    /**
     * Ensure payroll COA codes exist for the business (idempotent).
     */
    public function ensurePayrollAccounts(int $businessId): void
    {
        $this->journalService->ensurePayrollAccounts($businessId);
    }

    /**
     * Progressive Uganda PAYE on monthly taxable pay (after employee NSSF).
     *
     * @param  list<array{up_to: int|float|null, rate: float, base_tax: float|int}>  $brackets
     */
    public function calculatePaye(float $taxable, array $brackets): float
    {
        return $this->calculator->calculatePaye($taxable, $brackets);
    }

    public function resolveStatutoryRates(int $businessId, string $asOfDate): HrStatutoryRateSet
    {
        return $this->calculator->resolveStatutoryRates($businessId, $asOfDate);
    }

    public function calculateEmployeePay(HrEmployeeCompensation $comp, HrStatutoryRateSet $rates): array
    {
        return $this->calculator->calculateEmployeePay($comp, $rates);
    }

    public function latestCompensation(int $businessId, int $employeeId, string $asOfDate): ?HrEmployeeCompensation
    {
        return $this->calculator->latestCompensation($businessId, $employeeId, $asOfDate);
    }
}
