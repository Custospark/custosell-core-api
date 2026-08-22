<?php

declare(strict_types=1);

namespace App\Repositories\Eloquent;

use App\Models\PipelineAutomationRule;
use App\Models\PipelineAutomationRun;
use App\Repositories\Contracts\PipelineAutomationRuleRepositoryInterface;
use Illuminate\Support\Collection;

class PipelineAutomationRuleRepository implements PipelineAutomationRuleRepositoryInterface
{
    public function forBoard(int $boardId): Collection
    {
        return PipelineAutomationRule::query()
            ->where('board_id', $boardId)
            ->orderByDesc('is_active')
            ->orderBy('id')
            ->get();
    }

    public function activeScheduledRules(): Collection
    {
        return PipelineAutomationRule::query()
            ->where('is_active', true)
            ->get()
            ->filter(fn (PipelineAutomationRule $rule) => $rule->isScheduledTrigger());
    }

    public function find(int $id): ?PipelineAutomationRule
    {
        return PipelineAutomationRule::query()->find($id);
    }

    public function create(array $data): PipelineAutomationRule
    {
        return PipelineAutomationRule::create($data);
    }

    public function update(PipelineAutomationRule $rule, array $data): PipelineAutomationRule
    {
        $rule->update($data);

        return $rule->fresh();
    }

    public function delete(PipelineAutomationRule $rule): bool
    {
        return (bool) $rule->delete();
    }

    public function markRun(PipelineAutomationRule $rule, bool $success = true): void
    {
        $rule->update([
            'run_count' => (int) $rule->run_count + 1,
            'last_run_at' => now(),
            'consecutive_failures' => $success ? 0 : (int) $rule->consecutive_failures + 1,
        ]);
    }

    public function recordRun(PipelineAutomationRule $rule, string $status, int $actionsExecuted, ?int $leadId = null, ?string $message = null, array $detail = []): void
    {
        PipelineAutomationRun::create([
            'rule_id' => $rule->id,
            'business_id' => $rule->business_id,
            'lead_id' => $leadId,
            'trigger' => (string) ($rule->trigger['type'] ?? ''),
            'status' => $status,
            'actions_executed' => $actionsExecuted,
            'message' => $message,
            'detail' => $detail,
        ]);
    }

    public function recentRuns(int $ruleId, int $limit = 10): Collection
    {
        return PipelineAutomationRun::query()
            ->where('rule_id', $ruleId)
            ->orderByDesc('created_at')
            ->limit($limit)
            ->get();
    }
}