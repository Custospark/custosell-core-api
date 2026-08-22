<?php

declare(strict_types=1);

namespace App\Services\Pipeline;

use App\Models\PipelineAutomationRule;
use App\Models\PipelineBoard;
use App\Models\PipelineLead;
use App\Repositories\Contracts\PipelineAutomationRuleRepositoryInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * The cron-driven automation engine. Every minute it scans active rules whose
 * trigger is scheduled (time/state based), finds the matching leads, evaluates
 * conditions, executes actions, and records the run - all idempotently.
 *
 * Loop guard: actions that change a lead's state can re-trigger event hooks,
 * so cards created by create_card are excluded from event hooks, and a
 * per-(rule, lead) "fired" signature stored in memory prevents a scheduled rule
 * from re-firing the same lead twice within a run.
 */
class PipelineAutomationSchedulerService
{
    /** Hard cap per rule per run to avoid runaway loops. */
    protected const MAX_LEADS_PER_RULE = 500;

    public function __construct(
        protected PipelineAutomationRuleRepositoryInterface $rules,
        protected PipelineAutomationConditionEvaluator $evaluator,
        protected PipelineAutomationActionService $actions,
    ) {}

    /** @return array{checked: int, fired: int, executed: int} */
    public function runDue(): array
    {
        $checked = 0;
        $fired = 0;
        $executed = 0;

        $rules = $this->rules->activeScheduledRules();
        $firedThisRun = [];

        foreach ($rules as $rule) {
            // Frequency gate: only fire when the schedule matches now (e.g.
            // every Monday at 9am, on the 1st of the month, or a cron window).
            if (! $rule->frequencyMatches()) {
                continue;
            }

            // Recurring rules create one card per matching window. Guard on
            // last_run_at so the every-minute cron only creates it once.
            if ((string) ($rule->trigger['type'] ?? '') === 'recurring') {
                $runExecuted = $this->fireRecurring($rule);
                if ($runExecuted > 0) {
                    $fired++;
                    $executed += $runExecuted;
                }
                continue;
            }

            $leads = $this->leadsForRule($rule);
            $checked += count($leads);

            foreach ($leads as $lead) {
                $signature = $rule->id.':'.$lead->id;
                if (isset($firedThisRun[$signature])) {
                    continue;
                }

                $board = $lead->board ?? PipelineBoard::query()->find($lead->board_id);
                if (! $board) {
                    continue;
                }

                if (! $this->evaluator->passes($lead, $rule->conditions)) {
                    continue;
                }

                try {
                    $result = DB::transaction(function () use ($rule, $board, $lead) {
                        return $this->actions->execute($rule, $board, $lead);
                    });

                    $firedThisRun[$signature] = true;
                    $fired++;
                    $executed += (int) $result['executed'];
                    $this->rules->markRun($rule, success: true);
                    $this->rules->recordRun(
                        $rule,
                        \App\Models\PipelineAutomationRun::STATUS_SUCCESS,
                        (int) $result['executed'],
                        $lead->id,
                        null,
                        ['trigger' => (string) ($rule->trigger['type'] ?? '')],
                    );
                } catch (\Throwable $e) {
                    $this->rules->markRun($rule, success: false);
                    $this->rules->recordRun(
                        $rule,
                        \App\Models\PipelineAutomationRun::STATUS_FAILED,
                        0,
                        $lead->id,
                        $e->getMessage(),
                    );
                    Log::warning('Automation rule failed', [
                        'rule_id' => $rule->id,
                        'lead_id' => $lead->id,
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        }

        return ['checked' => $checked, 'fired' => $fired, 'executed' => $executed];
    }

    /**
     * Fire a recurring (create-card) rule once per matching time window. The
     * rule's last_run_at is only advanced when an action actually runs, so a
     * "every Monday 9am" rule creates exactly one card each Monday.
     *
     * @return int number of executed actions (0 when skipped)
     */
    protected function fireRecurring(PipelineAutomationRule $rule): int
    {
        $window = $rule->last_run_at ? $rule->last_run_at->startOfMinute() : null;
        if ($window && $window->gte(now()->startOfMinute())) {
            return 0;
        }

        $board = PipelineBoard::query()->find($rule->board_id);
        if (! $board) {
            return 0;
        }

        try {
            $result = DB::transaction(function () use ($rule, $board) {
                return $this->actions->execute($rule, $board, null);
            });

            if ((int) $result['executed'] > 0) {
                $this->rules->markRun($rule, success: true);
                $this->rules->recordRun(
                    $rule,
                    \App\Models\PipelineAutomationRun::STATUS_SUCCESS,
                    (int) $result['executed'],
                    null,
                    null,
                    ['trigger' => 'recurring'],
                );

                return (int) $result['executed'];
            }
        } catch (\Throwable $e) {
            $this->rules->markRun($rule, success: false);
            $this->rules->recordRun(
                $rule,
                \App\Models\PipelineAutomationRun::STATUS_FAILED,
                0,
                null,
                $e->getMessage(),
            );
            Log::warning('Recurring automation failed', [
                'rule_id' => $rule->id,
                'error' => $e->getMessage(),
            ]);
        }

        return 0;
    }

    /**
     * Find the leads a scheduled rule applies to, based on its trigger.
     *
     * @return \Illuminate\Support\Collection<int, PipelineLead>
     */
    protected function leadsForRule(PipelineAutomationRule $rule): \Illuminate\Support\Collection
    {
        $type = (string) ($rule->trigger['type'] ?? '');
        $offset = (int) ($rule->trigger['offset_days'] ?? 0);

        $query = PipelineLead::query()
            ->where('board_id', $rule->board_id)
            ->whereNotIn('status', ['archived'])
            ->limit(self::MAX_LEADS_PER_RULE);

        return match ($type) {
            PipelineAutomationRule::TRIGGER_DUE_PASSED => $query
                ->whereNotNull('due_date')
                ->where('due_date', '<', now())
                ->get(),
            PipelineAutomationRule::TRIGGER_OVERDUE_BY => $query
                ->whereNotNull('due_date')
                ->where('due_date', '<', now()->subDays(max(0, $offset)))
                ->get(),
            PipelineAutomationRule::TRIGGER_BEFORE_DUE => $query
                ->whereNotNull('due_date')
                ->where('due_date', '>', now())
                ->where('due_date', '<=', now()->addDays(max(0, $offset)))
                ->get(),
            PipelineAutomationRule::TRIGGER_STAGE_DWELL => $this->stageDwellLeads($query, $rule, $offset),
            PipelineAutomationRule::TRIGGER_NO_ACTIVITY => $this->noActivityLeads($query, $offset),
            PipelineAutomationRule::TRIGGER_CREATED_X_DAYS => $query
                ->where('created_at', '<=', now()->subDays(max(0, $offset)))
                ->get(),
            default => collect(),
        };
    }

    protected function stageDwellLeads($query, PipelineAutomationRule $rule, int $days): \Illuminate\Support\Collection
    {
        $stageId = (int) ($rule->trigger['stage_id'] ?? 0);
        $days = max(0, $days);

        $query = $query->where(function ($q) use ($stageId) {
            if ($stageId > 0) {
                $q->where('stage_id', $stageId);
            }
        });

        return $query->get()->filter(function (PipelineLead $lead) use ($days) {
            $entered = $lead->stage_entered_at ?? $lead->updated_at ?? $lead->created_at;
            return $entered !== null && $entered->lte(now()->subDays($days));
        });
    }

    protected function noActivityLeads($query, int $days): \Illuminate\Support\Collection
    {
        $days = max(0, $days);

        return $query->get()->filter(function (PipelineLead $lead) use ($days) {
            $lastActivity = $lead->activities()->max('created_at');
            $reference = $lastActivity ? \Illuminate\Support\Carbon::parse($lastActivity) : $lead->created_at;

            return $reference !== null && $reference->lte(now()->subDays($days));
        });
    }
}