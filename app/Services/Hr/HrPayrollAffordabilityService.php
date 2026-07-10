<?php

declare(strict_types=1);

namespace App\Services\Hr;

use App\Models\AccountingPeriod;
use App\Models\Hr\HrEmployee;
use App\Models\Hr\HrEmployeeCompensation;
use App\Services\LedgerService;
use Carbon\Carbon;
use Illuminate\Validation\ValidationException;

class HrPayrollAffordabilityService
{
    public function __construct(
        protected HrPayrollService $payroll,
        protected LedgerService $ledger,
    ) {}

    /**
     * @param  array{
     *   basic_salary: float|int|string,
     *   allowances?: list<array<string, mixed>>,
     *   deductions?: list<array<string, mixed>>,
     *   start_month_offset?: int
     * }|null  $hire
     * @return array<string, mixed>
     */
    public function analyze(
        int $businessId,
        ?string $asOfDate = null,
        ?int $periodId = null,
        int $horizonMonths = 3,
        ?array $hire = null,
    ): array {
        $asOf = Carbon::parse($asOfDate ?? now()->toDateString())->startOfDay();
        $asOfStr = $asOf->toDateString();
        $horizonMonths = max(1, min(24, $horizonMonths));

        $period = $this->resolvePeriod($businessId, $asOfStr, $periodId);

        $codes = config('accounting.default_account_codes');
        $cashCode = (string) ($codes['cash'] ?? '1101');
        $bankCode = (string) ($codes['bank'] ?? '1102');
        $salariesPayableCode = (string) ($codes['salaries_payable'] ?? '2110');
        $payePayableCode = (string) ($codes['paye_payable'] ?? '2111');
        $nssfPayableCode = (string) ($codes['nssf_payable'] ?? '2112');

        $cash1101 = round($this->ledger->closingBalanceByCodes($businessId, $period->id, [$cashCode]), 2);
        $bank1102 = round($this->ledger->closingBalanceByCodes($businessId, $period->id, [$bankCode]), 2);
        $cashAvailable = round($cash1101 + $bank1102, 2);

        $salariesPayable = round($this->ledger->closingBalanceByCodes($businessId, $period->id, [$salariesPayableCode]), 2);
        $payePayable = round($this->ledger->closingBalanceByCodes($businessId, $period->id, [$payePayableCode]), 2);
        $nssfPayable = round($this->ledger->closingBalanceByCodes($businessId, $period->id, [$nssfPayableCode]), 2);
        $unpaid = round($salariesPayable + $payePayable + $nssfPayable, 2);

        $burn = $this->computeBurn($businessId, $asOfStr);
        $monthlyBurn = (float) $burn['monthly_burn'];

        $coverage = $this->buildCoverage($cashAvailable, $unpaid, $monthlyBurn);
        $months = $this->buildMonths(
            $asOf,
            $horizonMonths,
            $cashAvailable,
            $unpaid,
            $monthlyBurn,
            0.0,
            0,
        );

        $warnings = [
            'Cash runway assumes no non-payroll cash inflows or other operating outflows.',
        ];
        if ($burn['employees_missing_compensation'] > 0) {
            array_unshift(
                $warnings,
                $burn['employees_missing_compensation'].' active employee(s) have no compensation as of as_of_date.',
            );
        }

        $hireScenario = null;
        if ($hire !== null) {
            $hireScenario = $this->buildHireScenario(
                $businessId,
                $asOfStr,
                $asOf,
                $horizonMonths,
                $cashAvailable,
                $unpaid,
                $monthlyBurn,
                $hire,
            );
        }

        return [
            'as_of_date' => $asOfStr,
            'period' => [
                'id' => $period->id,
                'name' => $period->name,
                'start_date' => $period->start_date->toDateString(),
                'end_date' => $period->end_date->toDateString(),
                'is_closed' => (bool) $period->is_closed,
            ],
            'cash' => [
                'cash_1101' => $cash1101,
                'bank_1102' => $bank1102,
                'cash_available' => $cashAvailable,
            ],
            'liabilities' => [
                'salaries_payable_2110' => $salariesPayable,
                'paye_payable_2111' => $payePayable,
                'nssf_payable_2112' => $nssfPayable,
                'unpaid_payroll_liabilities' => $unpaid,
            ],
            'burn' => $burn,
            'coverage' => $coverage,
            'months' => $months,
            'warnings' => $warnings,
            'hire_scenario' => $hireScenario,
        ];
    }

    protected function resolvePeriod(int $businessId, string $asOfDate, ?int $periodId): AccountingPeriod
    {
        if ($periodId !== null) {
            $period = AccountingPeriod::query()
                ->where('business_id', $businessId)
                ->whereKey($periodId)
                ->first();

            if (! $period) {
                throw ValidationException::withMessages([
                    'period_id' => 'Accounting period not found for this business.',
                ]);
            }

            return $period;
        }

        $covering = AccountingPeriod::query()
            ->where('business_id', $businessId)
            ->where('start_date', '<=', $asOfDate)
            ->where('end_date', '>=', $asOfDate)
            ->orderBy('is_closed') // open (false=0) preferred over closed
            ->orderByDesc('end_date')
            ->get();

        if ($covering->isNotEmpty()) {
            return $covering->first();
        }

        $latest = AccountingPeriod::query()
            ->where('business_id', $businessId)
            ->orderByDesc('end_date')
            ->first();

        if ($latest) {
            return $latest;
        }

        throw ValidationException::withMessages([
            'period_id' => 'No accounting period found. Create an accounting period before checking payroll affordability.',
        ]);
    }

    /**
     * @return array{
     *   employee_count: int,
     *   employees_missing_compensation: int,
     *   gross: float,
     *   paye: float,
     *   nssf_employee: float,
     *   nssf_employer: float,
     *   other_deductions: float,
     *   net: float,
     *   monthly_employer_cash_cost: float,
     *   monthly_burn: float
     * }
     */
    protected function computeBurn(int $businessId, string $asOfDate): array
    {
        $rates = $this->payroll->resolveStatutoryRates($businessId, $asOfDate);
        $employees = HrEmployee::query()
            ->where('business_id', $businessId)
            ->whereIn('status', ['active', 'on_leave', 'onboarding'])
            ->get();

        $employeeCount = $employees->count();
        $missing = 0;
        $gross = 0.0;
        $paye = 0.0;
        $nssfEmployee = 0.0;
        $nssfEmployer = 0.0;
        $otherDeductions = 0.0;
        $net = 0.0;

        foreach ($employees as $employee) {
            $comp = $this->payroll->latestCompensation($businessId, $employee->id, $asOfDate);
            if (! $comp) {
                $missing++;

                continue;
            }

            $calc = $this->payroll->calculateEmployeePay($comp, $rates);
            $gross += $calc['gross'];
            $paye += $calc['paye'];
            $nssfEmployee += $calc['nssf_employee'];
            $nssfEmployer += $calc['nssf_employer'];
            $otherDeductions += $calc['other_deductions'];
            $net += $calc['net'];
        }

        $monthlyBurn = round($gross + $nssfEmployer, 2);

        return [
            'employee_count' => $employeeCount,
            'employees_missing_compensation' => $missing,
            'gross' => round($gross, 2),
            'paye' => round($paye, 2),
            'nssf_employee' => round($nssfEmployee, 2),
            'nssf_employer' => round($nssfEmployer, 2),
            'other_deductions' => round($otherDeductions, 2),
            'net' => round($net, 2),
            'monthly_employer_cash_cost' => $monthlyBurn,
            'monthly_burn' => $monthlyBurn,
        ];
    }

    /**
     * @param  array{
     *   basic_salary: float|int|string,
     *   allowances?: list<array<string, mixed>>,
     *   deductions?: list<array<string, mixed>>,
     *   start_month_offset?: int
     * }  $hire
     * @return array<string, mixed>
     */
    protected function buildHireScenario(
        int $businessId,
        string $asOfDate,
        Carbon $asOf,
        int $horizonMonths,
        float $cashAvailable,
        float $unpaid,
        float $baseMonthlyBurn,
        array $hire,
    ): array {
        $rates = $this->payroll->resolveStatutoryRates($businessId, $asOfDate);
        $comp = new HrEmployeeCompensation([
            'business_id' => $businessId,
            'basic_salary' => $hire['basic_salary'],
            'allowances_json' => $hire['allowances'] ?? [],
            'deductions_json' => $hire['deductions'] ?? [],
            'effective_from' => $asOfDate,
        ]);

        $calc = $this->payroll->calculateEmployeePay($comp, $rates);
        $incremental = round($calc['gross'] + $calc['nssf_employer'], 2);
        $startOffset = max(0, (int) ($hire['start_month_offset'] ?? 0));

        // Steady-state burn once hire is active (for runway / coverage status).
        $steadyBurn = round($baseMonthlyBurn + $incremental, 2);
        $coverage = $this->buildCoverage($cashAvailable, $unpaid, $steadyBurn);
        $months = $this->buildMonths(
            $asOf,
            $horizonMonths,
            $cashAvailable,
            $unpaid,
            $baseMonthlyBurn,
            $incremental,
            $startOffset,
        );

        return [
            'incremental_monthly_burn' => $incremental,
            'hire_calc' => [
                'gross' => $calc['gross'],
                'paye' => $calc['paye'],
                'nssf_employee' => $calc['nssf_employee'],
                'nssf_employer' => $calc['nssf_employer'],
                'net' => $calc['net'],
                'employer_cash_cost' => $incremental,
            ],
            'coverage' => $coverage,
            'months' => $months,
        ];
    }

    /**
     * @return array{
     *   cash_after_arrears: float,
     *   runway_months: float|null,
     *   runway_months_floor: int,
     *   can_clear_arrears: bool,
     *   status: string
     * }
     */
    protected function buildCoverage(float $cashAvailable, float $unpaid, float $monthlyBurn): array
    {
        $cashAfterArrears = round($cashAvailable - $unpaid, 2);
        $canClearArrears = $cashAvailable >= $unpaid;

        if ($monthlyBurn <= 0) {
            return [
                'cash_after_arrears' => $cashAfterArrears,
                'runway_months' => null,
                'runway_months_floor' => 0,
                'can_clear_arrears' => $canClearArrears,
                'status' => 'unknown',
            ];
        }

        $runway = $cashAfterArrears / $monthlyBurn;
        $floor = $runway >= 0 ? max(0, (int) floor($runway)) : 0;

        if ($floor >= 3) {
            $status = 'healthy';
        } elseif ($floor >= 1) {
            $status = 'tight';
        } else {
            $status = 'critical';
        }

        return [
            'cash_after_arrears' => $cashAfterArrears,
            'runway_months' => round($runway, 2),
            'runway_months_floor' => $floor,
            'can_clear_arrears' => $canClearArrears,
            'status' => $status,
        ];
    }

    /**
     * @return list<array{
     *   offset: int,
     *   label: string,
     *   month_start: string,
     *   need: float,
     *   cash_available: float,
     *   surplus_deficit: float,
     *   can_cover: bool
     * }>
     */
    protected function buildMonths(
        Carbon $asOf,
        int $horizonMonths,
        float $cashAvailable,
        float $unpaid,
        float $baseMonthlyBurn,
        float $incrementalBurn,
        int $hireStartOffset,
    ): array {
        $burnAt = fn (int $offset): float => $baseMonthlyBurn + ($offset >= $hireStartOffset ? $incrementalBurn : 0);

        $effectiveBurn0 = $burnAt(0);
        $unaccrued = max(0, $effectiveBurn0 - min($effectiveBurn0, $unpaid));
        $need0 = round($unpaid + $unaccrued, 2);

        $months = [];
        for ($m = 0; $m < $horizonMonths; $m++) {
            if ($m === 0) {
                $need = $need0;
            } else {
                $forward = 0.0;
                for ($i = 1; $i <= $m; $i++) {
                    $forward += $burnAt($i);
                }
                $need = round($need0 + $forward, 2);
            }

            $surplus = round($cashAvailable - $need, 2);
            $monthStart = $asOf->copy()->startOfMonth()->addMonths($m);

            $months[] = [
                'offset' => $m,
                'label' => $this->monthLabel($m, $monthStart),
                'month_start' => $monthStart->toDateString(),
                'need' => $need,
                'cash_available' => $cashAvailable,
                'surplus_deficit' => $surplus,
                'can_cover' => $cashAvailable >= $need,
            ];
        }

        return $months;
    }

    protected function monthLabel(int $offset, Carbon $monthStart): string
    {
        return match ($offset) {
            0 => 'This month',
            1 => 'Next month',
            default => $monthStart->format('F Y'),
        };
    }
}
