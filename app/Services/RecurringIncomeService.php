<?php

namespace App\Services;

use App\Models\IncomeSource;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Processes recurring income: when a recurring income source's next_due_date
 * arrives, a new occurrence is created for that date and the series advances.
 * The original record stays as the series template; the fired occurrence is a
 * normal income source so the chain never re-fires from the copy.
 */
class RecurringIncomeService
{
    public function __construct(
        protected IncomeSourceService $incomeSourceService,
    ) {}

    /**
     * Fire every recurring income source that is due. Returns the number of
     * new occurrences created.
     */
    public function processDue(?\Carbon\CarbonInterface $asOf = null): int
    {
        $now = $asOf ?? now();
        $created = 0;

        $templates = IncomeSource::query()
            ->where('is_recurring', true)
            ->whereNotNull('next_due_date')
            ->where('next_due_date', '<=', $now)
            ->get();

        foreach ($templates as $template) {
            try {
                $ownerId = (int) ($template->user_id
                    ?? $template->business?->owner_id
                    ?? $template->loadMissing('business')->business?->owner_id
                    ?? 0);
                if ($ownerId <= 0) {
                    Log::warning('Recurring income skipped - no owner user', ['income_source_id' => $template->id]);
                    continue;
                }

                $created += DB::transaction(function () use ($template, $ownerId, $now) {
                    $occurrenceDate = Carbon::parse($template->next_due_date);
                    $nextDue = $this->advance($occurrenceDate, $template->recurrence_interval);

                    $occurrence = $this->incomeSourceService->create(
                        (int) $template->business_id,
                        $ownerId,
                        [
                            'budget_id' => $template->budget_id,
                            'amount' => (float) $template->amount,
                            'source_name' => $template->source_name ?? 'Recurring income',
                            'description' => $template->description,
                            'income_date' => $occurrenceDate->toDateString(),
                            'is_recurring' => false,
                            'recurrence_interval' => null,
                            'next_due_date' => null,
                        ],
                    );

                    $template->update(['next_due_date' => $nextDue->toDateString()]);

                    return $occurrence ? 1 : 0;
                });
            } catch (\Throwable $e) {
                Log::warning('Recurring income occurrence failed', [
                    'income_source_id' => $template->id,
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