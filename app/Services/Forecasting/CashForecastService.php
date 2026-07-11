<?php

declare(strict_types=1);

namespace App\Services\Forecasting;

use App\Models\Expense;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Services\Hr\HrPayrollAffordabilityService;
use Carbon\Carbon;
use Illuminate\Validation\ValidationException;

class CashForecastService
{
    public function __construct(
        protected HrPayrollAffordabilityService $affordability,
    ) {}

    /**
     * @param  array{
     *   hire?: array{basic_salary: float|int|string, allowances?: list<array<string, mixed>>, deductions?: list<array<string, mixed>>, start_month_offset?: int}|null,
     *   extra_monthly_opex?: float|int,
     *   revenue_uplift_pct?: float|int
     * }|null  $overrides
     * @return array<string, mixed>
     */
    public function forecast(
        int $businessId,
        ?string $asOfDate = null,
        ?int $periodId = null,
        int $horizonMonths = 6,
        ?array $overrides = null,
    ): array {
        $horizonMonths = max(1, min(24, $horizonMonths));
        $asOf = Carbon::parse($asOfDate ?? now()->toDateString())->startOfDay();
        $asOfStr = $asOf->toDateString();

        $hire = $overrides['hire'] ?? null;
        $extraOpex = round((float) ($overrides['extra_monthly_opex'] ?? 0), 2);
        $upliftPct = (float) ($overrides['revenue_uplift_pct'] ?? 0);

        try {
            $payroll = $this->affordability->analyze(
                $businessId,
                $asOfStr,
                $periodId,
                $horizonMonths,
                $hire,
            );
        } catch (ValidationException $e) {
            throw $e;
        }

        $cashAvailable = (float) $payroll['cash']['cash_available'];
        $unpaid = (float) $payroll['liabilities']['unpaid_payroll_liabilities'];
        $basePayrollBurn = (float) $payroll['burn']['monthly_burn'];
        $incrementalHire = 0.0;
        $hireStartOffset = 0;
        if ($hire !== null && is_array($payroll['hire_scenario'] ?? null)) {
            $incrementalHire = (float) $payroll['hire_scenario']['incremental_monthly_burn'];
            $hireStartOffset = max(0, (int) ($hire['start_month_offset'] ?? 0));
        }

        $trailingOpex = $this->trailingMonthlyOpex($businessId, $asOf);
        $monthlyOpex = round($trailingOpex + $extraOpex, 2);

        $trailingNetSales = $this->trailingNetSales($businessId, $asOf);
        $assumptions = [];
        $warnings = array_values($payroll['warnings'] ?? []);

        if ($trailingNetSales <= 0) {
            $assumedInflow = 0.0;
            $warnings[] = 'No trailing 30-day net sales found; assumed monthly inflow is 0.';
            $assumptions[] = 'Monthly inflow assumed at 0 because trailing 30-day net sales are zero or missing.';
        } else {
            $assumedInflow = $trailingNetSales;
            $assumptions[] = 'Monthly inflow assumed equal to trailing 30-day net sales (gross − refunds).';
        }

        if ($upliftPct != 0.0) {
            $assumedInflow = round($assumedInflow * (1 + ($upliftPct / 100)), 2);
            $assumptions[] = 'Inflows adjusted by revenue uplift of '.$upliftPct.'%.';
        }

        $assumptions[] = 'Payroll burn reused from HrPayrollAffordabilityService (gross + employer NSSF).';
        $assumptions[] = 'Operating expense burn uses trailing 30-day expense total as a monthly proxy.';
        if ($extraOpex > 0) {
            $assumptions[] = 'Extra monthly opex of '.$extraOpex.' applied to the forecast.';
        }
        if ($incrementalHire > 0) {
            $assumptions[] = 'Hire scenario adds '.$incrementalHire.' to monthly payroll burn from month offset '.$hireStartOffset.'.';
        }

        $months = $this->buildMonthLadder(
            $asOf,
            $horizonMonths,
            $cashAvailable,
            $unpaid,
            $basePayrollBurn,
            $incrementalHire,
            $hireStartOffset,
            $monthlyOpex,
            $assumedInflow,
        );

        $coverage = $this->buildCoverage(
            $cashAvailable,
            $unpaid,
            $basePayrollBurn + $incrementalHire + $monthlyOpex - $assumedInflow,
        );

        return [
            'as_of_date' => $asOfStr,
            'horizon_months' => $horizonMonths,
            'period' => $payroll['period'],
            'cash' => $payroll['cash'],
            'liabilities' => $payroll['liabilities'],
            'burn' => [
                'payroll' => $payroll['burn'],
                'monthly_payroll_burn' => $basePayrollBurn,
                'incremental_hire_burn' => $incrementalHire,
                'monthly_opex' => $monthlyOpex,
                'trailing_30d_opex' => $trailingOpex,
                'extra_monthly_opex' => $extraOpex,
                'monthly_total_burn' => round($basePayrollBurn + $incrementalHire + $monthlyOpex, 2),
            ],
            'inflows' => [
                'trailing_30d_net_sales' => $trailingNetSales,
                'assumed_monthly_inflow' => $assumedInflow,
                'revenue_uplift_pct' => $upliftPct,
            ],
            'coverage' => $coverage,
            'months' => $months,
            'hire_scenario' => $payroll['hire_scenario'] ?? null,
            'assumptions' => $assumptions,
            'warnings' => $warnings,
        ];
    }

    protected function trailingMonthlyOpex(int $businessId, Carbon $asOf): float
    {
        $from = $asOf->copy()->subDays(29)->startOfDay();
        $to = $asOf->copy()->endOfDay();

        return round((float) Expense::query()
            ->where('business_id', $businessId)
            ->whereBetween('expense_date', [$from, $to])
            ->sum('amount'), 2);
    }

    protected function trailingNetSales(int $businessId, Carbon $asOf): float
    {
        $from = $asOf->copy()->subDays(29)->startOfDay();
        $to = $asOf->copy()->endOfDay();

        $sales = Sale::query()
            ->where('business_id', $businessId)
            ->whereBetween('sale_date', [$from, $to])
            ->get(['id', 'total_amount']);

        $gross = (float) $sales->sum('total_amount');
        $refunds = (float) SaleItem::query()
            ->whereIn('sale_id', $sales->pluck('id'))
            ->sum('refunded_amount');

        return round(max(0, $gross - $refunds), 2);
    }

    /**
     * @return list<array<string, mixed>>
     */
    protected function buildMonthLadder(
        Carbon $asOf,
        int $horizonMonths,
        float $cashAvailable,
        float $unpaid,
        float $basePayrollBurn,
        float $incrementalHire,
        int $hireStartOffset,
        float $monthlyOpex,
        float $assumedInflow,
    ): array {
        $payrollAt = fn (int $offset): float => $basePayrollBurn + ($offset >= $hireStartOffset ? $incrementalHire : 0);

        $months = [];
        $opening = $cashAvailable;

        for ($m = 0; $m < $horizonMonths; $m++) {
            $payroll = $payrollAt($m);
            $inflow = $assumedInflow;
            $opex = $monthlyOpex;

            // Month 0 clears unpaid payroll liabilities first (same spirit as affordability).
            $liabilityClear = $m === 0 ? $unpaid : 0.0;
            $netChange = round($inflow - $payroll - $opex - $liabilityClear, 2);
            $closing = round($opening + $netChange, 2);
            $monthStart = $asOf->copy()->startOfMonth()->addMonths($m);

            $months[] = [
                'offset' => $m,
                'label' => $this->monthLabel($m, $monthStart),
                'month_start' => $monthStart->toDateString(),
                'opening_cash' => round($opening, 2),
                'inflows' => $inflow,
                'payroll_outflow' => $payroll,
                'opex_outflow' => $opex,
                'liability_clear' => $liabilityClear,
                'net_change' => $netChange,
                'closing_cash' => $closing,
                'cash_available' => $cashAvailable,
                'surplus_deficit' => $closing,
                'can_cover' => $closing >= 0,
            ];

            $opening = $closing;
        }

        return $months;
    }

    /**
     * @return array{cash_after_arrears: float, runway_months: float|null, runway_months_floor: int, can_clear_arrears: bool, status: string}
     */
    protected function buildCoverage(float $cashAvailable, float $unpaid, float $netMonthlyBurn): array
    {
        $cashAfterArrears = round($cashAvailable - $unpaid, 2);
        $canClearArrears = $cashAvailable >= $unpaid;

        if ($netMonthlyBurn <= 0) {
            return [
                'cash_after_arrears' => $cashAfterArrears,
                'runway_months' => null,
                'runway_months_floor' => 0,
                'can_clear_arrears' => $canClearArrears,
                'status' => $netMonthlyBurn < 0 ? 'healthy' : 'unknown',
            ];
        }

        $runway = $cashAfterArrears / $netMonthlyBurn;
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

    protected function monthLabel(int $offset, Carbon $monthStart): string
    {
        return match ($offset) {
            0 => 'This month',
            1 => 'Next month',
            default => $monthStart->format('F Y'),
        };
    }
}
