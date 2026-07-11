<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Forecasting;

use App\Http\Controllers\Controller;
use App\Services\Forecasting\BudgetVsActualService;
use App\Services\Forecasting\CashForecastService;
use App\Services\Forecasting\ForecastBudgetService;
use App\Services\Forecasting\ForecastKpiService;
use App\Services\Forecasting\ForecastScenarioService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ForecastingController extends Controller
{
    public function __construct(
        protected CashForecastService $cashForecast,
        protected BudgetVsActualService $budgetVsActual,
        protected ForecastBudgetService $budgets,
        protected ForecastKpiService $kpis,
        protected ForecastScenarioService $scenarios,
    ) {}

    public function overview(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'as_of_date' => ['nullable', 'date'],
            'horizon_months' => ['nullable', 'integer', 'min:1', 'max:24'],
            'period_id' => ['nullable', 'integer'],
            'start_date' => ['nullable', 'date'],
            'end_date' => ['nullable', 'date'],
        ]);

        $businessId = (int) $request->user()->business_id;
        $cash = $this->cashForecast->forecast(
            $businessId,
            $validated['as_of_date'] ?? null,
            isset($validated['period_id']) ? (int) $validated['period_id'] : null,
            (int) ($validated['horizon_months'] ?? 6),
        );
        $bva = $this->budgetVsActual->analyze(
            $businessId,
            isset($validated['period_id']) ? (int) $validated['period_id'] : null,
            $validated['start_date'] ?? null,
            $validated['end_date'] ?? null,
        );

        return response()->json([
            'data' => [
                'cash_forecast' => $cash,
                'budget_vs_actual' => $bva,
                'assumptions' => array_values(array_unique([
                    ...($cash['assumptions'] ?? []),
                    ...($bva['assumptions'] ?? []),
                ])),
                'warnings' => array_values(array_unique([
                    ...($cash['warnings'] ?? []),
                    ...($bva['warnings'] ?? []),
                ])),
            ],
        ]);
    }

    public function cashForecast(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'as_of_date' => ['nullable', 'date'],
            'horizon_months' => ['nullable', 'integer', 'min:1', 'max:24'],
            'period_id' => ['nullable', 'integer'],
        ]);

        $data = $this->cashForecast->forecast(
            (int) $request->user()->business_id,
            $validated['as_of_date'] ?? null,
            isset($validated['period_id']) ? (int) $validated['period_id'] : null,
            (int) ($validated['horizon_months'] ?? 6),
        );

        return response()->json(['data' => $data]);
    }

    public function budgetVsActual(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'period_id' => ['nullable', 'integer'],
            'start_date' => ['nullable', 'date'],
            'end_date' => ['nullable', 'date'],
        ]);

        $data = $this->budgetVsActual->analyze(
            (int) $request->user()->business_id,
            isset($validated['period_id']) ? (int) $validated['period_id'] : null,
            $validated['start_date'] ?? null,
            $validated['end_date'] ?? null,
        );

        return response()->json(['data' => $data]);
    }

    public function kpis(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'mode' => ['nullable', 'string', 'in:auto,retail,saas'],
            'as_of_date' => ['nullable', 'date'],
        ]);

        $data = $this->kpis->kpis(
            (int) $request->user()->business_id,
            $validated['mode'] ?? 'auto',
            $validated['as_of_date'] ?? null,
        );

        return response()->json(['data' => $data]);
    }

    // --- Budgets ---

    public function indexBudgets(Request $request): JsonResponse
    {
        return response()->json([
            'data' => $this->budgets->listBudgets((int) $request->user()->business_id),
        ]);
    }

    public function showBudget(Request $request, int $id): JsonResponse
    {
        return response()->json([
            'data' => $this->budgets->showBudget((int) $request->user()->business_id, $id),
        ]);
    }

    public function storeBudget(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'year' => ['required', 'integer', 'min:2000', 'max:2100'],
            'name' => ['required', 'string', 'max:255'],
            'status' => ['nullable', 'string', 'in:draft,active,archived'],
        ]);

        return response()->json([
            'data' => $this->budgets->createBudget((int) $request->user()->business_id, $validated),
        ], 201);
    }

    public function updateBudget(Request $request, int $id): JsonResponse
    {
        $validated = $request->validate([
            'year' => ['sometimes', 'integer', 'min:2000', 'max:2100'],
            'name' => ['sometimes', 'string', 'max:255'],
            'status' => ['sometimes', 'string', 'in:draft,active,archived'],
        ]);

        return response()->json([
            'data' => $this->budgets->updateBudget((int) $request->user()->business_id, $id, $validated),
        ]);
    }

    public function destroyBudget(Request $request, int $id): JsonResponse
    {
        $this->budgets->deleteBudget((int) $request->user()->business_id, $id);

        return response()->json(['message' => 'Budget deleted.']);
    }

    public function storeLine(Request $request, int $id): JsonResponse
    {
        $validated = $request->validate([
            'expense_category_id' => ['nullable', 'integer', 'exists:expense_categories,id'],
            'label' => ['required', 'string', 'max:255'],
            'amount' => ['nullable', 'numeric', 'min:0'],
            'justification' => ['nullable', 'string'],
            'zbb_status' => ['nullable', 'string', 'in:draft,justified,approved'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
        ]);

        return response()->json([
            'data' => $this->budgets->createLine((int) $request->user()->business_id, $id, $validated),
        ], 201);
    }

    public function updateLine(Request $request, int $id, int $lineId): JsonResponse
    {
        $validated = $request->validate([
            'expense_category_id' => ['nullable', 'integer', 'exists:expense_categories,id'],
            'label' => ['sometimes', 'string', 'max:255'],
            'amount' => ['sometimes', 'numeric', 'min:0'],
            'justification' => ['nullable', 'string'],
            'zbb_status' => ['sometimes', 'string', 'in:draft,justified,approved'],
            'sort_order' => ['sometimes', 'integer', 'min:0'],
        ]);

        return response()->json([
            'data' => $this->budgets->updateLine((int) $request->user()->business_id, $id, $lineId, $validated),
        ]);
    }

    public function destroyLine(Request $request, int $id, int $lineId): JsonResponse
    {
        $this->budgets->deleteLine((int) $request->user()->business_id, $id, $lineId);

        return response()->json(['message' => 'Budget line deleted.']);
    }

    public function justifyLine(Request $request, int $id, int $lineId): JsonResponse
    {
        $validated = $request->validate([
            'justification' => ['required', 'string', 'min:1'],
        ]);

        return response()->json([
            'data' => $this->budgets->justifyLine((int) $request->user()->business_id, $id, $lineId, $validated),
        ]);
    }

    public function approveLine(Request $request, int $id, int $lineId): JsonResponse
    {
        return response()->json([
            'data' => $this->budgets->approveLine((int) $request->user()->business_id, $id, $lineId),
        ]);
    }

    public function rollBudget(Request $request, int $id): JsonResponse
    {
        $validated = $request->validate([
            'label' => ['nullable', 'string', 'max:255'],
            'as_of_date' => ['nullable', 'date'],
        ]);

        return response()->json([
            'data' => $this->budgets->roll(
                (int) $request->user()->business_id,
                $id,
                (int) $request->user()->id,
                $validated['label'] ?? null,
                $validated['as_of_date'] ?? null,
            ),
        ], 201);
    }

    public function indexSnapshots(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'forecast_budget_id' => ['nullable', 'integer'],
        ]);

        return response()->json([
            'data' => $this->budgets->listSnapshots(
                (int) $request->user()->business_id,
                isset($validated['forecast_budget_id']) ? (int) $validated['forecast_budget_id'] : null,
            ),
        ]);
    }

    // --- Scenarios ---

    public function indexScenarios(Request $request): JsonResponse
    {
        return response()->json([
            'data' => $this->scenarios->list((int) $request->user()->business_id),
        ]);
    }

    public function showScenario(Request $request, int $id): JsonResponse
    {
        return response()->json([
            'data' => $this->scenarios->show((int) $request->user()->business_id, $id),
        ]);
    }

    public function storeScenario(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'horizon_months' => ['nullable', 'integer', 'min:1', 'max:24'],
            'hire_basic_salary' => ['nullable', 'numeric', 'min:0'],
            'extra_monthly_opex' => ['nullable', 'numeric', 'min:0'],
            'revenue_uplift_pct' => ['nullable', 'numeric'],
            'payload_json' => ['nullable', 'array'],
        ]);

        return response()->json([
            'data' => $this->scenarios->create((int) $request->user()->business_id, $validated),
        ], 201);
    }

    public function updateScenario(Request $request, int $id): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'horizon_months' => ['sometimes', 'integer', 'min:1', 'max:24'],
            'hire_basic_salary' => ['nullable', 'numeric', 'min:0'],
            'extra_monthly_opex' => ['sometimes', 'numeric', 'min:0'],
            'revenue_uplift_pct' => ['sometimes', 'numeric'],
            'payload_json' => ['nullable', 'array'],
        ]);

        return response()->json([
            'data' => $this->scenarios->update((int) $request->user()->business_id, $id, $validated),
        ]);
    }

    public function destroyScenario(Request $request, int $id): JsonResponse
    {
        $this->scenarios->delete((int) $request->user()->business_id, $id);

        return response()->json(['message' => 'Scenario deleted.']);
    }

    public function runScenario(Request $request, int $id): JsonResponse
    {
        $validated = $request->validate([
            'as_of_date' => ['nullable', 'date'],
            'period_id' => ['nullable', 'integer'],
        ]);

        $data = $this->scenarios->run(
            (int) $request->user()->business_id,
            $id,
            $validated['as_of_date'] ?? null,
            isset($validated['period_id']) ? (int) $validated['period_id'] : null,
        );

        return response()->json(['data' => $data]);
    }
}
