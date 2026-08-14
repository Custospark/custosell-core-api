<?php

declare(strict_types=1);

namespace App\Services\Hr;

use App\Models\Hr\HrEmployeeCompensation;
use App\Models\Hr\HrStatutoryRateSet;

class HrPayrollCalculator
{
    /** Default Uganda PAYE monthly brackets (2024-ish). */
    public const DEFAULT_PAYE_BRACKETS = [
        ['up_to' => 235000, 'rate' => 0, 'base_tax' => 0],
        ['up_to' => 335000, 'rate' => 0.10, 'base_tax' => 0],
        ['up_to' => 410000, 'rate' => 0.20, 'base_tax' => 10000],
        ['up_to' => 10000000, 'rate' => 0.30, 'base_tax' => 25000],
        ['up_to' => null, 'rate' => 0.40, 'base_tax' => 2902500],
    ];

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
    public function latestCompensation(int $businessId, int $employeeId, string $asOfDate): ?HrEmployeeCompensation
    {
        return HrEmployeeCompensation::query()
            ->where('business_id', $businessId)
            ->where('employee_id', $employeeId)
            ->where('effective_from', '<=', $asOfDate)
            ->orderByDesc('effective_from')
            ->first();
    }

    /** @param  array<int|string, mixed>  $items */
    public function sumNamedAmounts(array $items): float
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
