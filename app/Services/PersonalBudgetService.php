<?php

namespace App\Services;

use App\Models\BudgetLine;
use App\Models\Expense;
use App\Models\PersonalBudget;
use App\Repositories\Contracts\PersonalBudgetRepositoryInterface;
use App\Services\Contracts\PersonalBudgetServiceInterface;
use Illuminate\Support\Facades\DB;

class PersonalBudgetService implements PersonalBudgetServiceInterface
{
    public function __construct(
        protected PersonalBudgetRepositoryInterface $personalBudgetRepository,
    ) {}

    public function getAll(int $businessId, array $filters = []): array
    {
        return $this->personalBudgetRepository->summarise($businessId, $filters);
    }

    public function getById(int $id): ?PersonalBudget
    {
        return $this->personalBudgetRepository->find($id);
    }

    public function create(int $businessId, int $userId, array $data): PersonalBudget
    {
        $data['business_id'] = $businessId;
        $data['user_id'] = $userId;
        $data['status'] = $data['status'] ?? 'active';
        return $this->personalBudgetRepository->create($data);
    }

    public function update(int $id, array $data): PersonalBudget
    {
        $budget = $this->personalBudgetRepository->find($id);
        if (!$budget) {
            throw new \RuntimeException('Budget not found');
        }
        return $this->personalBudgetRepository->update($budget, $data);
    }

    public function delete(int $id): bool
    {
        $budget = $this->personalBudgetRepository->find($id);
        if (!$budget) {
            throw new \RuntimeException('Budget not found');
        }
        return $this->personalBudgetRepository->delete($budget);
    }

    /**
     * Replace the budget's plan lines (upsert by id, delete removed, keep
     * purchased). The planned amount is the user's target and is NOT derived
     * from line totals. Returns the refreshed plan lines.
     */
    public function syncLines(int $id, array $lines): array
    {
        $budget = $this->personalBudgetRepository->find($id);
        if (!$budget) {
            throw new \RuntimeException('Budget not found');
        }

        DB::transaction(function () use ($budget, $lines) {
            // Keep existing purchased lines (their expense link stays), replace the rest.
            $existing = $budget->lines()->get()->keyBy('id');
            $kept = [];
            $saved = [];

            foreach ($lines as $line) {
                $lineId = $line['id'] ?? null;
                $quantity = max(1, (int) ($line['quantity'] ?? 1));
                $unitPrice = max(0, (float) ($line['unit_price'] ?? 0));
                $lineTotal = round($quantity * $unitPrice, 2);

                if ($lineId && $existing->has($lineId) && ($existing[$lineId]->purchased ?? false)) {
                    $saved[] = $existing[$lineId]->fresh();
                    continue;
                }

                if ($lineId && $existing->has($lineId)) {
                    $model = $existing[$lineId];
                    $model->fill([
                        'item_name' => $line['item_name'] ?? $model->item_name,
                        'quantity' => $quantity,
                        'unit_price' => $unitPrice,
                        'line_total' => $lineTotal,
                    ])->save();
                    $saved[] = $model->fresh();
                } else {
                    $saved[] = $budget->lines()->create([
                        'item_name' => $line['item_name'] ?? 'Item',
                        'quantity' => $quantity,
                        'unit_price' => $unitPrice,
                        'line_total' => $lineTotal,
                        'purchased' => false,
                    ]);
                }
            }

            foreach ($existing as $model) {
                if (!collect($saved)->contains('id', $model->id)) {
                    $model->delete();
                }
            }
        });

        return $budget->fresh('lines')->lines()->orderBy('id')->get()->toArray();
    }

    /**
     * Convert a purchased plan line into a real expense, linked to the budget
     * (and the line via expense_id).
     */
    public function purchaseLine(int $id, int $lineId, array $expenseData, ?int $userId): BudgetLine
    {
        $budget = $this->personalBudgetRepository->find($id);
        if (!$budget) {
            throw new \RuntimeException('Budget not found');
        }
        $line = BudgetLine::where('id', $lineId)->where('personal_budget_id', $id)->first();
        if (!$line) {
            throw new \RuntimeException('Plan line not found');
        }
        if ($line->purchased && $line->expense_id) {
            return $line->fresh('expense');
        }

        return DB::transaction(function () use ($budget, $line, $expenseData, $userId) {
            $expense = Expense::create([
                'business_id' => $budget->business_id,
                'budget_id' => $budget->id,
                'recorded_by' => $userId,
                'amount' => $line->line_total,
                'description' => $expenseData['description'] ?? ('Bought: ' . $line->item_name),
                'expense_date' => $expenseData['expense_date'] ?? now()->toDateString(),
                'reference' => $expenseData['reference'] ?? null,
            ]);

            $line->update([
                'purchased' => true,
                'expense_id' => $expense->id,
            ]);

            return $line->fresh('expense');
        });
    }
}