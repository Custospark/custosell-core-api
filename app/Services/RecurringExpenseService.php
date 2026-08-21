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
     * Fire every recurring expense that is due in the user's browser timezone.
     * Returns the number of new occurrences created.
     */
    public function processDue(?\Carbon\CarbonInterface $asOf = null): int
    {
        $now = $asOf ?? now();
        $created = 0;

        $templates = Expense::query()
            ->where('is_recurring', true)
            ->whereNotNull('next_due_date')
            ->get();

        foreach ($templates as $template) {
            try {
                $tz = $this->resolveTimezone($template->recurrence_timezone);
                $localToday = $now->copy()->setTimezone($tz)->toDateString();
                $dueDate = $template->next_due_date instanceof \Carbon\CarbonInterface
                    ? $template->next_due_date->toDateString()
                    : \Illuminate\Support\Carbon::parse($template->next_due_date)->toDateString();

                // Fire only once the user's local calendar day has reached the due date.
                if ($localToday < $dueDate) {
                    continue;
                }

                // Stop the series once past the end date (compared in the user's timezone).
                if ($template->recurrence_end_date && $localToday > $template->recurrence_end_date->toDateString()) {
                    $template->update([
                        'is_recurring' => false,
                        'recurrence_interval' => null,
                        'recurrence_end_date' => null,
                        'recurrence_timezone' => null,
                        'next_due_date' => null,
                    ]);

                    continue;
                }

                $created += DB::transaction(function () use ($template, $dueDate) {
                    $occurrenceDate = \Illuminate\Support\Carbon::parse($dueDate);
                    $nextDue = $this->advance($occurrenceDate, $template->recurrence_interval);

                    // Stop the series when the next occurrence would pass the end date.
                    if ($template->recurrence_end_date && $nextDue->greaterThan($template->recurrence_end_date)) {
                        $template->update([
                            'is_recurring' => false,
                            'recurrence_interval' => null,
                            'recurrence_end_date' => null,
                            'recurrence_timezone' => null,
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
                        'recurrence_timezone' => null,
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

    protected function resolveTimezone(?string $timezone): string
    {
        try {
            return $timezone && in_array($timezone, \DateTimeZone::listIdentifiers(), true) ? $timezone : 'UTC';
        } catch (\Throwable) {
            return 'UTC';
        }
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