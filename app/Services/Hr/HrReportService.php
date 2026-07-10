<?php

declare(strict_types=1);

namespace App\Services\Hr;

use App\Models\Hr\HrPayRun;
use Illuminate\Validation\ValidationException;

class HrReportService
{
    public function __construct(
        protected HrPayrollService $payroll,
    ) {}

    /** @return array{pay_run: array<string, mixed>, rows: list<array<string, mixed>>, totals: array<string, float>} */
    public function payeSchedule(int $businessId, int $payRunId): array
    {
        $payRun = $this->payroll->findPayRunOrFail($businessId, $payRunId);
        $this->assertHasLines($payRun);

        $rows = [];
        $totalGross = 0.0;
        $totalPaye = 0.0;
        $totalTaxable = 0.0;

        foreach ($payRun->lines as $line) {
            $taxable = (float) ($line->breakdown_json['taxable'] ?? max(0, (float) $line->gross - (float) $line->nssf_employee));
            $rows[] = [
                'employee_id' => $line->employee_id,
                'employee_number' => $line->employee?->employee_number,
                'employee_name' => $line->employee?->full_name,
                'gross' => (float) $line->gross,
                'nssf_employee' => (float) $line->nssf_employee,
                'taxable' => $taxable,
                'paye' => (float) $line->paye,
            ];
            $totalGross += (float) $line->gross;
            $totalPaye += (float) $line->paye;
            $totalTaxable += $taxable;
        }

        return [
            'pay_run' => $this->payRunMeta($payRun),
            'rows' => $rows,
            'totals' => [
                'gross' => round($totalGross, 2),
                'taxable' => round($totalTaxable, 2),
                'paye' => round($totalPaye, 2),
            ],
        ];
    }

    /** @return array{pay_run: array<string, mixed>, rows: list<array<string, mixed>>, totals: array<string, float>} */
    public function nssfSchedule(int $businessId, int $payRunId): array
    {
        $payRun = $this->payroll->findPayRunOrFail($businessId, $payRunId);
        $this->assertHasLines($payRun);

        $rows = [];
        $totalEmployee = 0.0;
        $totalEmployer = 0.0;
        $totalPensionable = 0.0;

        foreach ($payRun->lines as $line) {
            $pensionable = (float) ($line->breakdown_json['pensionable'] ?? $line->breakdown_json['earnings']['basic_salary'] ?? 0);
            $rows[] = [
                'employee_id' => $line->employee_id,
                'employee_number' => $line->employee?->employee_number,
                'employee_name' => $line->employee?->full_name,
                'pensionable' => $pensionable,
                'nssf_employee' => (float) $line->nssf_employee,
                'nssf_employer' => (float) $line->nssf_employer,
                'nssf_total' => round((float) $line->nssf_employee + (float) $line->nssf_employer, 2),
            ];
            $totalEmployee += (float) $line->nssf_employee;
            $totalEmployer += (float) $line->nssf_employer;
            $totalPensionable += $pensionable;
        }

        return [
            'pay_run' => $this->payRunMeta($payRun),
            'rows' => $rows,
            'totals' => [
                'pensionable' => round($totalPensionable, 2),
                'nssf_employee' => round($totalEmployee, 2),
                'nssf_employer' => round($totalEmployer, 2),
                'nssf_total' => round($totalEmployee + $totalEmployer, 2),
            ],
        ];
    }

    /** Period-based schedules: find pay runs overlapping the period and aggregate. */
    public function payeScheduleForPeriod(int $businessId, string $periodStart, string $periodEnd): array
    {
        $payRuns = HrPayRun::query()
            ->where('business_id', $businessId)
            ->whereIn('status', ['calculated', 'approved', 'posted'])
            ->where('period_start', '<=', $periodEnd)
            ->where('period_end', '>=', $periodStart)
            ->with(['lines.employee:id,first_name,last_name,employee_number'])
            ->get();

        $rows = [];
        $totals = ['gross' => 0.0, 'taxable' => 0.0, 'paye' => 0.0];

        foreach ($payRuns as $payRun) {
            $schedule = $this->payeSchedule($businessId, $payRun->id);
            foreach ($schedule['rows'] as $row) {
                $row['pay_run_id'] = $payRun->id;
                $rows[] = $row;
            }
            $totals['gross'] += $schedule['totals']['gross'];
            $totals['taxable'] += $schedule['totals']['taxable'];
            $totals['paye'] += $schedule['totals']['paye'];
        }

        return [
            'period' => ['start' => $periodStart, 'end' => $periodEnd],
            'rows' => $rows,
            'totals' => [
                'gross' => round($totals['gross'], 2),
                'taxable' => round($totals['taxable'], 2),
                'paye' => round($totals['paye'], 2),
            ],
        ];
    }

    public function nssfScheduleForPeriod(int $businessId, string $periodStart, string $periodEnd): array
    {
        $payRuns = HrPayRun::query()
            ->where('business_id', $businessId)
            ->whereIn('status', ['calculated', 'approved', 'posted'])
            ->where('period_start', '<=', $periodEnd)
            ->where('period_end', '>=', $periodStart)
            ->pluck('id');

        $rows = [];
        $totals = ['pensionable' => 0.0, 'nssf_employee' => 0.0, 'nssf_employer' => 0.0, 'nssf_total' => 0.0];

        foreach ($payRuns as $payRunId) {
            $schedule = $this->nssfSchedule($businessId, (int) $payRunId);
            foreach ($schedule['rows'] as $row) {
                $row['pay_run_id'] = $payRunId;
                $rows[] = $row;
            }
            foreach ($totals as $key => $_) {
                $totals[$key] += $schedule['totals'][$key];
            }
        }

        return [
            'period' => ['start' => $periodStart, 'end' => $periodEnd],
            'rows' => $rows,
            'totals' => array_map(fn ($v) => round($v, 2), $totals),
        ];
    }

    protected function assertHasLines(HrPayRun $payRun): void
    {
        if ($payRun->lines->isEmpty()) {
            throw ValidationException::withMessages([
                'pay_run' => 'Pay run has no calculated lines.',
            ]);
        }
    }

    /** @return array<string, mixed> */
    protected function payRunMeta(HrPayRun $payRun): array
    {
        return [
            'id' => $payRun->id,
            'period_start' => $payRun->period_start->toDateString(),
            'period_end' => $payRun->period_end->toDateString(),
            'status' => $payRun->status,
        ];
    }
}
