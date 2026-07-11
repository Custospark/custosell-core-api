<?php

declare(strict_types=1);

namespace App\Services\Forecasting;

use App\Models\Forecasting\ForecastScenario;
use Illuminate\Validation\ValidationException;

class ForecastScenarioService
{
    public function __construct(
        protected CashForecastService $cashForecast,
    ) {}

    /**
     * @return list<array<string, mixed>>
     */
    public function list(int $businessId): array
    {
        return ForecastScenario::query()
            ->where('business_id', $businessId)
            ->orderByDesc('id')
            ->get()
            ->map(fn (ForecastScenario $s) => $this->serialize($s))
            ->all();
    }

    public function show(int $businessId, int $id): array
    {
        return $this->serialize($this->find($businessId, $id));
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(int $businessId, array $data): array
    {
        $scenario = ForecastScenario::create([
            'business_id' => $businessId,
            'name' => $data['name'],
            'horizon_months' => (int) ($data['horizon_months'] ?? 6),
            'hire_basic_salary' => $data['hire_basic_salary'] ?? null,
            'extra_monthly_opex' => $data['extra_monthly_opex'] ?? 0,
            'revenue_uplift_pct' => $data['revenue_uplift_pct'] ?? 0,
            'payload_json' => $data['payload_json'] ?? null,
        ]);

        return $this->serialize($scenario);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(int $businessId, int $id, array $data): array
    {
        $scenario = $this->find($businessId, $id);
        $scenario->fill(array_intersect_key($data, array_flip([
            'name',
            'horizon_months',
            'hire_basic_salary',
            'extra_monthly_opex',
            'revenue_uplift_pct',
            'payload_json',
        ])));
        $scenario->save();

        return $this->serialize($scenario);
    }

    public function delete(int $businessId, int $id): void
    {
        $this->find($businessId, $id)->delete();
    }

    /**
     * @return array<string, mixed>
     */
    public function run(
        int $businessId,
        int $id,
        ?string $asOfDate = null,
        ?int $periodId = null,
    ): array {
        $scenario = $this->find($businessId, $id);
        $horizon = max(1, min(24, (int) $scenario->horizon_months));

        $baseline = $this->cashForecast->forecast(
            $businessId,
            $asOfDate,
            $periodId,
            $horizon,
        );

        $overrides = [
            'extra_monthly_opex' => (float) $scenario->extra_monthly_opex,
            'revenue_uplift_pct' => (float) $scenario->revenue_uplift_pct,
        ];

        if ($scenario->hire_basic_salary !== null && (float) $scenario->hire_basic_salary > 0) {
            $overrides['hire'] = [
                'basic_salary' => (float) $scenario->hire_basic_salary,
                'allowances' => [],
                'deductions' => [],
                'start_month_offset' => 0,
            ];
        }

        $scenarioForecast = $this->cashForecast->forecast(
            $businessId,
            $asOfDate,
            $periodId,
            $horizon,
            $overrides,
        );

        $assumptions = array_values(array_unique([
            ...($baseline['assumptions'] ?? []),
            ...($scenarioForecast['assumptions'] ?? []),
            'Scenario run compares baseline cash ladder vs scenario with hire / extra opex / revenue uplift.',
        ]));
        $warnings = array_values(array_unique([
            ...($baseline['warnings'] ?? []),
            ...($scenarioForecast['warnings'] ?? []),
        ]));

        return [
            'scenario' => $this->serialize($scenario),
            'baseline' => $baseline,
            'scenario_forecast' => $scenarioForecast,
            'delta' => [
                'monthly_total_burn' => round(
                    (float) $scenarioForecast['burn']['monthly_total_burn'] - (float) $baseline['burn']['monthly_total_burn'],
                    2,
                ),
                'assumed_monthly_inflow' => round(
                    (float) $scenarioForecast['inflows']['assumed_monthly_inflow'] - (float) $baseline['inflows']['assumed_monthly_inflow'],
                    2,
                ),
                'closing_cash_last_month' => round(
                    (float) ($scenarioForecast['months'][$horizon - 1]['closing_cash'] ?? 0)
                    - (float) ($baseline['months'][$horizon - 1]['closing_cash'] ?? 0),
                    2,
                ),
            ],
            'assumptions' => $assumptions,
            'warnings' => $warnings,
        ];
    }

    protected function find(int $businessId, int $id): ForecastScenario
    {
        $scenario = ForecastScenario::query()
            ->where('business_id', $businessId)
            ->whereKey($id)
            ->first();

        if (! $scenario) {
            throw ValidationException::withMessages([
                'id' => 'Forecast scenario not found.',
            ]);
        }

        return $scenario;
    }

    protected function serialize(ForecastScenario $scenario): array
    {
        return [
            'id' => $scenario->id,
            'business_id' => $scenario->business_id,
            'name' => $scenario->name,
            'horizon_months' => $scenario->horizon_months,
            'hire_basic_salary' => $scenario->hire_basic_salary !== null ? (float) $scenario->hire_basic_salary : null,
            'extra_monthly_opex' => (float) $scenario->extra_monthly_opex,
            'revenue_uplift_pct' => (float) $scenario->revenue_uplift_pct,
            'payload_json' => $scenario->payload_json,
            'created_at' => $scenario->created_at?->toIso8601String(),
            'updated_at' => $scenario->updated_at?->toIso8601String(),
        ];
    }
}
