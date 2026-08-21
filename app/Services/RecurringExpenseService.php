<?php

namespace App\Services;

use App\Models\Expense;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Processes recurring expenses: when a recurring expense's next_due_date
 * arrives, a new occurrence is created for that date and the series advances.
 * The original recurring record stays as the series template (is_recurring
 * remains true and next_due_date moves forward); the fired occurrence itself
 * is a normal expense so the chain never re-fires from the copy.
 */
class RecurringExpenseService
{
    public function __construct(
        protected ExpenseService $expenseService,
    ) {}

    /**
     * Fire every recurring expense that is due. Returns the number of new
     * occurrences created.
     */
    public function processDue(?\Carbon\CarbonInterface $asOf = null): int
    {
        $now = $asOf ?? now();
        $created = 0;

        $templates = Expense::query()
            ->where('is_recurring', true)
            ->whereNotNull('next_due_date')
            ->where('next_due_date', '<=', $now)
            ->where(function ($q) use ($now) {
                $q->whereNull('recurrence_end_date')
                    ->orWhere('recurrence_end_date', '>=', $now->toDateString());
            })
            ->get();

        foreach ($templates as $template) {
            try {
                $created += DB::transaction(function () use ($template, $now) {
                    $occurrenceDate = Carbon::parse($template->next_due_date);
                    $nextDue = $this->advance($occurrenceDate, $template->recurrence_interval);

                    // Stop the series when the next occurrence would pass the end date.
                    if ($template->recurrence_end_date && $nextDue->greaterThan(Carbon::parse($template->recurrence_end_date))) {
                        $template->update([
                            'is_recurring' => false,
                            'recurrence_interval' => null,
                            'recurrence_end_date' => null,
                            'next_due_date' => null,
                        ]);

                        return 0;
                    }

                    $occurrence = $this->expenseService->create((int) $template->business_id, [
                        'budget_id' => $template->budget_id,
                        'expense_category_id' => $template->expense_category_id,
                        'recorded_by' => $template->recorded_by,
                        'location_id' => $template->location_id,
                        'project_id' => $template->project_id,
                        'fixed_asset_id' => $template->fixed_asset_id,
                        'amount' => (float) $template->amount,
                        'description' => ($template->description ?? '') !== ''
                            ? $template->description
                            : 'Recurring expense',
                        'reference' => $template->reference,
                        'supplier_tin' => $template->supplier_tin,
                        'supplier_invoice_no' => $template->supplier_invoice_no,
                        'vat_amount' => $template->vat_amount,
                        'vat_claimable' => (bool) $template->vat_claimable,
                        'is_recurring' => false,
                        'recurrence_interval' => null,
                        'recurrence_end_date' => null,
                        'next_due_date' => null,
                        'expense_date' => $occurrenceDate->toDateString(),
                    ]);

                    $template->update(['next_due_date' => $nextDue->toDateString()]);

                    return $occurrence ? 1 : 0;
                });
            } catch (\Throwable $e) {
                Log::warning('Recurring expense occurrence failed', [
                    'expense_id' => $template->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $created;
    }

    protected function advance(Carbon $date, ?string $interval): Carbon
    {
        return match ($interval) {
            'daily' => $date->copy()->addDay(),
            'weekly' => $date->copy()->addWeek(),
            'yearly' => $date->copy()->addYear(),
            default => $date->copy()->addMonth(),
        };
    }
}