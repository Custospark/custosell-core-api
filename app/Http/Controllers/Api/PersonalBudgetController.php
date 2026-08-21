<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\PersonalBudgetRequest;
use App\Http\Requests\PurchaseLineRequest;
use App\Http\Resources\PersonalBudgetResource;
use App\Models\BudgetLine;
use App\Models\Business;
use App\Models\Expense;
use App\Models\IncomeSource;
use App\Models\PersonalBudget;
use App\Services\BudgetPdfBuilder;
use App\Services\Contracts\PersonalBudgetServiceInterface;
use App\Services\ReportExportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class PersonalBudgetController extends Controller
{
    public function __construct(
        protected PersonalBudgetServiceInterface $personalBudgetService,
        protected BudgetPdfBuilder $budgetPdfBuilder,
        protected ReportExportService $export,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $businessId = $request->user()->business_id;
        $filters = $request->only(['status', 'date_from', 'date_to']);
        return response()->json(
            $this->personalBudgetService->getAll($businessId, $filters)
        );
    }

    public function show(Request $request, int $id): JsonResponse
    {
        $budget = $this->findOwned($request, $id);
        $lines = $budget->lines()->with('expense')->orderBy('id')->get();
        $expenses = Expense::where('budget_id', $id)->orderByDesc('expense_date')->limit(50)->get(['id', 'amount', 'description', 'expense_date']);
        $income = IncomeSource::where('budget_id', $id)->orderByDesc('income_date')->limit(50)->get(['id', 'amount', 'source_name', 'income_date']);
        return response()->json([
            'data' => new PersonalBudgetResource($budget),
            'lines' => $lines,
            'expenses' => $expenses,
            'income' => $income,
            'summary' => $this->summarise($id),
        ]);
    }

    public function store(PersonalBudgetRequest $request): JsonResponse
    {
        $budget = $this->personalBudgetService->create(
            $request->user()->business_id,
            $request->user()->id,
            $request->validated()
        );
        return response()->json(['data' => new PersonalBudgetResource($budget)], 201);
    }

    public function update(PersonalBudgetRequest $request, int $id): JsonResponse
    {
        $this->assertOwned($request, $id);
        $budget = $this->personalBudgetService->update($id, $request->validated());
        return response()->json(['data' => new PersonalBudgetResource($budget)]);
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $this->assertOwned($request, $id);
        $this->personalBudgetService->delete($id);
        return response()->json(null, 204);
    }

    public function download(Request $request, int $id): Response
    {
        $budget = $this->findOwned($request, $id);
        if (!$budget) {
            abort(404, 'Budget not found');
        }
        $user = $request->user();
        $business = $user->business ?? new Business(['name' => $user->name, 'currency' => 'UGX']);

        $config = $this->budgetPdfBuilder->build($budget, $business, $user);

        return $this->export->downloadPdf(
            $config['view'],
            $config['data'],
            $config['filename'],
            $config['orientation'],
        );
    }

    public function syncLines(Request $request, int $id): JsonResponse
    {
        $this->assertOwned($request, $id);
        $lines = $request->validate([
            'lines' => ['required', 'array'],
            'lines.*.id' => ['nullable', 'integer'],
            'lines.*.item_name' => ['required', 'string', 'max:255'],
            'lines.*.quantity' => ['nullable', 'numeric', 'gt:0'],
            'lines.*.unit_price' => ['nullable', 'numeric', 'min:0'],
        ]);
        $saved = $this->personalBudgetService->syncLines($id, $lines['lines']);
        return response()->json(['data' => $saved]);
    }

    public function purchaseLine(PurchaseLineRequest $request, int $id, int $lineId): JsonResponse
    {
        $this->assertOwned($request, $id);
        $line = $this->personalBudgetService->purchaseLine(
            $id,
            $lineId,
            $request->validated(),
            $request->user()->id
        );
        return response()->json(['data' => $line->load('expense')]);
    }

    public function affordability(Request $request, int $id): JsonResponse
    {
        $this->assertOwned($request, $id);
        return response()->json($this->affordabilityFor($id));
    }

    public function moneySummary(Request $request): JsonResponse
    {
        $businessId = $request->user()->business_id;
        $filters = $request->only(['date_from', 'date_to']);
        $dateFrom = $filters['date_from'] ?? now()->startOfMonth()->toDateString();
        $dateTo = $filters['date_to'] ?? now()->endOfMonth()->toDateString();

        $income = (float) IncomeSource::where('business_id', $businessId)
            ->whereBetween('income_date', [$dateFrom, $dateTo])->sum('amount');
        $expense = (float) Expense::where('business_id', $businessId)
            ->whereBetween('expense_date', [$dateFrom, $dateTo])->sum('amount');
        $savings = $income - $expense;

        $budgets = PersonalBudget::where('business_id', $businessId)->where('status', 'active')->get();

        $plannedTotal = (float) $budgets->sum('planned_amount');
        $spentTotal = (float) Expense::where('business_id', $businessId)
            ->whereNotNull('budget_id')
            ->whereBetween('expense_date', [$dateFrom, $dateTo])->sum('amount');

        $afford = $plannedTotal <= $income;
        $recommendation = $afford
            ? 'Your planned budgets fit within your income for this period. Great - you can handle your current plans.'
            : 'Your planned budgets (' . number_format($plannedTotal, 2) . ') are more than your income (' . number_format($income, 2) . '). Consider trimming some plans or boosting income.';

        return response()->json([
            'income' => round($income, 2),
            'expense' => round($expense, 2),
            'savings' => round($savings, 2),
            'planned_total' => round($plannedTotal, 2),
            'spent_in_budgets' => round($spentTotal, 2),
            'budget_count' => $budgets->count(),
            'affordable' => $afford,
            'recommendation' => $recommendation,
        ]);
    }

    public function alerts(Request $request): JsonResponse
    {
        $businessId = $request->user()->business_id;
        $budgets = $this->personalBudgetService->getAll($businessId, ['status' => 'active']);
        $items = [];
        foreach ($budgets['budgets'] as $b) {
            $pct = (float) $b['percentage'];
            if ($pct >= 100) {
                $items[] = [
                    'level' => 'over',
                    'budget_id' => $b['id'],
                    'name' => $b['name'],
                    'message' => 'You\u2019ve spent ' . number_format($pct, 1) . '% of "' . $b['name'] . '" - over your planned amount.',
                    'remaining' => $b['remaining'],
                ];
            } elseif ($pct >= 80) {
                $items[] = [
                    'level' => 'near',
                    'budget_id' => $b['id'],
                    'name' => $b['name'],
                    'message' => 'You\u2019re at ' . number_format($pct, 1) . '% of "' . $b['name'] . '" - almost at your plan.',
                    'remaining' => $b['remaining'],
                ];
            }
        }
        $users = \App\Models\User::where('business_id', $businessId)->pluck('id');
        return response()->json(['alerts' => $items]);
    }

    protected function assertOwned(Request $request, int $id): void
    {
        if (!$this->findOwned($request, $id)) {
            abort(404, 'Budget not found');
        }
    }

    protected function findOwned(Request $request, int $id): ?PersonalBudget
    {
        return PersonalBudget::where('id', $id)->where('business_id', $request->user()->business_id)->first();
    }

    protected function summarise(int $id): array
    {
        $budget = PersonalBudget::find($id);
        $planned = (float) $budget->planned_amount;
        $spent = (float) Expense::where('budget_id', $id)->sum('amount');
        $income = (float) IncomeSource::where('budget_id', $id)->sum('amount');
        $remaining = $planned - $spent;
        $percentage = $planned > 0 ? round(($spent / $planned) * 100, 1) : 0;
        return [
            'planned' => round($planned, 2),
            'actual_spend' => round($spent, 2),
            'actual_income' => round($income, 2),
            'remaining' => round($remaining, 2),
            'percentage' => $percentage,
        ];
    }

    protected function affordabilityFor(int $id): array
    {
        $budget = PersonalBudget::find($id);
        $income = (float) IncomeSource::where('business_id', $budget->business_id)
            ->where(function ($q) {
                $q->orWhereDate('income_date', '>=', now()->startOfMonth())
                    ->orWhere('is_recurring', true);
            })->sum('amount');
        $spent = (float) Expense::where('budget_id', $id)->sum('amount');
        $remaining = (float) $budget->planned_amount - $spent;
        $canHandle = $income >= max($remaining, 0);
        return [
            'income_available' => round($income, 2),
            'plan_remaining' => round(max($remaining, 0), 2),
            'can_handle' => $canHandle,
            'recommendation' => $canHandle
                ? 'You can handle this budget with your current income - it fits comfortably. Keep going.'
                : 'This plan (' . number_format($budget->planned_amount, 2) . ') is more than your income (' . number_format($income, 2) . '). Consider trimming this plan or adding income.',
        ];
    }
}