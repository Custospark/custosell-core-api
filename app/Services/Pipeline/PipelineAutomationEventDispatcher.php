<?php

declare(strict_types=1);

namespace App\Services\Pipeline;

use App\Models\PipelineAutomationRule;
use App\Models\PipelineBoard;
use App\Models\PipelineLead;
use App\Models\User;

/**
 * Fires automation rules whose trigger is event-based (stage_entered,
 * status_changed, card_created, assigned, ...) immediately when a user action
 * happens. The scheduler handles time/state triggers; this handles the
 * immediate ones - so automation is driven by both the cron AND live events.
 */
class PipelineAutomationEventDispatcher
{
    /** Prevents automation triggering automation infinitely. */
    protected const MAX_DEPTH = 10;

    protected int $depth = 0;

    protected ?PipelineAutomationActionService $resolvedActions = null;

    public function __construct(
        protected PipelineAutomationConditionEvaluator $evaluator,
        protected \App\Repositories\Contracts\PipelineAutomationRuleRepositoryInterface $rules,
    ) {}

    public function cardCreated(PipelineLead $lead, User $actor): void
    {
        $this->fire($lead, PipelineAutomationRule::TRIGGER_CARD_CREATED, $actor);
    }

    public function stageEntered(PipelineLead $lead, User $actor): void
    {
        $this->fire($lead, PipelineAutomationRule::TRIGGER_STAGE_ENTERED, $actor);
    }

    public function statusChanged(PipelineLead $lead, User $actor): void
    {
        $this->fire($lead, PipelineAutomationRule::TRIGGER_STATUS_CHANGED, $actor);
    }

    public function assigned(PipelineLead $lead, User $actor): void
    {
        $this->fire($lead, PipelineAutomationRule::TRIGGER_ASSIGNED, $actor);
    }

    public function labelAdded(PipelineLead $lead, User $actor): void
    {
        $this->fire($lead, PipelineAutomationRule::TRIGGER_LABEL_ADDED, $actor);
    }

    public function fieldChanged(PipelineLead $lead, User $actor): void
    {
        $this->fire($lead, PipelineAutomationRule::TRIGGER_FIELD_CHANGED, $actor);
    }

    public function converted(PipelineLead $lead, User $actor): void
    {
        $this->fire($lead, PipelineAutomationRule::TRIGGER_CONVERTED, $actor);
    }

    protected function actions(): PipelineAutomationActionService
    {
        return $this->resolvedActions ??= app(PipelineAutomationActionService::class);
    }

    /** @return list<PipelineAutomationRule> */
    protected function rulesFor(PipelineLead $lead, string $triggerType): array
    {
        return \App\Models\PipelineAutomationRule::query()
            ->where('board_id', $lead->board_id)
            ->where('is_active', true)
            ->get()
            ->filter(fn (PipelineAutomationRule $rule) => (string) ($rule->trigger['type'] ?? '') === $triggerType)
            ->all();
    }

    protected function fire(PipelineLead $lead, string $triggerType, User $actor): void
    {
        if ($this->depth >= self::MAX_DEPTH) {
            \Illuminate\Support\Facades\Log::warning('Automation recursion guard hit', [
                'trigger' => $triggerType,
                'lead_id' => $lead->id,
            ]);

            return;
        }

        $board = $lead->board ?? PipelineBoard::query()->find($lead->board_id);
        if (! $board) {
            return;
        }

        $this->depth++;

        try {
            foreach ($this->rulesFor($lead, $triggerType) as $rule) {
                if (! $this->evaluator->passes($lead, $rule->conditions)) {
                    continue;
                }

                try {
                    $result = $this->actions()->execute($rule, $board, $lead, $actor);
                    if ((int) $result['executed'] > 0) {
                        $this->rules->markRun($rule, success: true);
                        $this->rules->recordRun(
                            $rule,
                            \App\Models\PipelineAutomationRun::STATUS_SUCCESS,
                            (int) $result['executed'],
                            $lead->id,
                            null,
                            ['trigger' => $triggerType],
                        );
                    }
                } catch (\Throwable $e) {
                    $this->rules->recordRun(
                        $rule,
                        \App\Models\PipelineAutomationRun::STATUS_FAILED,
                        0,
                        $lead->id,
                        $e->getMessage(),
                    );
                    \Illuminate\Support\Facades\Log::warning('Event automation failed', [
                        'rule_id' => $rule->id,
                        'lead_id' => $lead->id,
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        } finally {
            $this->depth--;
        }
    }
}