<?php

declare(strict_types=1);

namespace App\Services\Forecasting;

use App\Models\Expense;
use App\Models\Forecasting\ForecastBudget;
use App\Models\Forecasting\ForecastBudgetLine;
use App\Models\Forecasting\ForecastSnapshot;
use Illuminate\Validation\ValidationException;

class ForecastBudgetService
{
    /**
     * @return list<array<string, mixed>>
     */
    public function listBudgets(int $businessId): array
    {
        return ForecastBudget::query()
            ->where('business_id', $businessId)
            ->with('lines')
            ->orderByDesc('year')
            ->orderByDesc('id')
            ->get()
            ->map(fn (ForecastBudget $b) => $this->serializeBudget($b))
            ->all();
    }

    public function showBudget(int $businessId, int $id): array
    {
        $budget = $this->findBudget($businessId, $id);

        return $this->serializeBudget($budget->load('lines'));
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function createBudget(int $businessId, array $data): array
    {
        $budget = ForecastBudget::create([
            'business_id' => $businessId,
            'year' => (int) $data['year'],
            'name' => $data['name'],
            'status' => $data['status'] ?? 'draft',
        ]);

        return $this->serializeBudget($budget->load('lines'));
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function updateBudget(int $businessId, int $id, array $data): array
    {
        $budget = $this->findBudget($businessId, $id);
        $budget->fill(array_intersect_key($data, array_flip(['year', 'name', 'status'])));
        $budget->save();

        return $this->serializeBudget($budget->load('lines'));
    }

    public function deleteBudget(int $businessId, int $id): void
    {
        $this->findBudget($businessId, $id)->delete();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function createLine(int $businessId, int $budgetId, array $data): array
    {
        $budget = $this->findBudget($businessId, $budgetId);

        $line = ForecastBudgetLine::create([
            'forecast_budget_id' => $budget->id,
            'business_id' => $businessId,
            'expense_category_id' => $data['expense_category_id'] ?? null,
            'label' => $data['label'],
            'amount' => $data['amount'] ?? 0,
            'justification' => $data['justification'] ?? null,
            'zbb_status' => $data['zbb_status'] ?? 'draft',
            'sort_order' => (int) ($data['sort_order'] ?? 0),
        ]);

        return $this->serializeLine($line);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function updateLine(int $businessId, int $budgetId, int $lineId, array $data): array
    {
        $line = $this->findLine($businessId, $budgetId, $lineId);
        $line->fill(array_intersect_key($data, array_flip([
            'expense_category_id',
            'label',
            'amount',
            'justification',
            'zbb_status',
            'sort_order',
        ])));
        $line->save();

        return $this->serializeLine($line);
    }

    public function deleteLine(int $businessId, int $budgetId, int $lineId): void
    {
        $this->findLine($businessId, $budgetId, $lineId)->delete();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function justifyLine(int $businessId, int $budgetId, int $lineId, array $data): array
    {
        $line = $this->findLine($businessId, $budgetId, $lineId);
        $justification = trim((string) ($data['justification'] ?? ''));
        if ($justification === '') {
            throw ValidationException::withMessages([
                'justification' => 'Justification is required.',
            ]);
        }

        $line->justification = $justification;
        $line->zbb_status = 'justified';
        $line->save();

        return $this->serializeLine($line);
    }

    public function approveLine(int $businessId, int $budgetId, int $lineId): array
    {
        $line = $this->findLine($businessId, $budgetId, $lineId);
        if (trim((string) $line->justification) === '') {
            throw ValidationException::withMessages([
                'justification' => 'Cannot approve a line without justification.',
            ]);
        }

        $line->zbb_status = 'approved';
        $line->save();

        return $this->serializeLine($line);
    }

    public function roll(int $businessId, int $budgetId, int $userId, ?string $label = null, ?string $asOfDate = null): array
    {
        $budget = $this->findBudget($businessId, $budgetId)->load('lines');
        $asOf = $asOfDate ?? now()->toDateString();
        $yearStart = $budget->year.'-01-01';
        $ytdActuals = (float) Expense::query()
            ->where('business_id', $businessId)
            ->whereBetween('expense_date', [$yearStart, $asOf.' 23:59:59'])
            ->sum('amount');

        $payload = [
            'budget' => $this->serializeBudget($budget),
            'ytd_actuals' => [
                'as_of_date' => $asOf,
                'year_start' => $yearStart,
                'total_expenses' => round($ytdActuals, 2),
                'note' => 'YTD actual expenses from '.$yearStart.' through '.$asOf.'.',
            ],
            'rolled_at' => now()->toIso8601String(),
        ];

        $snapshot = ForecastSnapshot::create([
            'business_id' => $businessId,
            'forecast_budget_id' => $budget->id,
            'label' => $label ?: ('Roll '.$asOf),
            'as_of_date' => $asOf,
            'payload_json' => $payload,
            'created_by' => $userId,
        ]);

        return [
            'id' => $snapshot->id,
            'business_id' => $snapshot->business_id,
            'forecast_budget_id' => $snapshot->forecast_budget_id,
            'label' => $snapshot->label,
            'as_of_date' => $snapshot->as_of_date->toDateString(),
            'payload_json' => $snapshot->payload_json,
            'created_by' => $snapshot->created_by,
            'created_at' => $snapshot->created_at?->toIso8601String(),
            'assumptions' => [
                'Snapshot captures the current budget lines plus YTD expense actuals for the budget year.',
            ],
            'warnings' => [],
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listSnapshots(int $businessId, ?int $budgetId = null): array
    {
        $query = ForecastSnapshot::query()
            ->where('business_id', $businessId)
            ->orderByDesc('id');

        if ($budgetId !== null) {
            $query->where('forecast_budget_id', $budgetId);
        }

        return $query->get()->map(fn (ForecastSnapshot $s) => [
            'id' => $s->id,
            'business_id' => $s->business_id,
            'forecast_budget_id' => $s->forecast_budget_id,
            'label' => $s->label,
            'as_of_date' => $s->as_of_date->toDateString(),
            'payload_json' => $s->payload_json,
            'created_by' => $s->created_by,
            'created_at' => $s->created_at?->toIso8601String(),
        ])->all();
    }

    protected function findBudget(int $businessId, int $id): ForecastBudget
    {
        $budget = ForecastBudget::query()
            ->where('business_id', $businessId)
            ->whereKey($id)
            ->first();

        if (! $budget) {
            throw ValidationException::withMessages([
                'id' => 'Forecast budget not found.',
            ]);
        }

        return $budget;
    }

    protected function findLine(int $businessId, int $budgetId, int $lineId): ForecastBudgetLine
    {
        $this->findBudget($businessId, $budgetId);

        $line = ForecastBudgetLine::query()
            ->where('business_id', $businessId)
            ->where('forecast_budget_id', $budgetId)
            ->whereKey($lineId)
            ->first();

        if (! $line) {
            throw ValidationException::withMessages([
                'lineId' => 'Forecast budget line not found.',
            ]);
        }

        return $line;
    }

    protected function serializeBudget(ForecastBudget $budget): array
    {
        return [
            'id' => $budget->id,
            'business_id' => $budget->business_id,
            'year' => $budget->year,
            'name' => $budget->name,
            'status' => $budget->status,
            'lines' => $budget->relationLoaded('lines')
                ? $budget->lines->map(fn (ForecastBudgetLine $l) => $this->serializeLine($l))->values()->all()
                : [],
            'created_at' => $budget->created_at?->toIso8601String(),
            'updated_at' => $budget->updated_at?->toIso8601String(),
        ];
    }

    protected function serializeLine(ForecastBudgetLine $line): array
    {
        return [
            'id' => $line->id,
            'forecast_budget_id' => $line->forecast_budget_id,
            'business_id' => $line->business_id,
            'expense_category_id' => $line->expense_category_id,
            'label' => $line->label,
            'amount' => (float) $line->amount,
            'justification' => $line->justification,
            'zbb_status' => $line->zbb_status,
            'sort_order' => $line->sort_order,
            'created_at' => $line->created_at?->toIso8601String(),
            'updated_at' => $line->updated_at?->toIso8601String(),
        ];
    }
}
