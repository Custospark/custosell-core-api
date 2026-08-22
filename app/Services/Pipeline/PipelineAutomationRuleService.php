<?php

declare(strict_types=1);

namespace App\Services\Pipeline;

use App\Models\PipelineAutomationRule;
use App\Models\User;
use App\Repositories\Contracts\PipelineAutomationRuleRepositoryInterface;
use App\Services\Contracts\PipelineAutomationRuleServiceInterface;
use Illuminate\Support\Facades\DB;

class PipelineAutomationRuleService implements PipelineAutomationRuleServiceInterface
{
    public function __construct(
        protected PipelineAutomationRuleRepositoryInterface $rules,
        protected PipelineBoardService $boards,
        protected PipelineBoardPermissionService $permission,
    ) {}

    public function listForBoard(int $businessId, User $user, int $boardId): array
    {
        $this->boards->getBoard($businessId, $user, $boardId);

        return $this->rules->forBoard($boardId)
            ->map(fn (PipelineAutomationRule $rule) => $this->serialize($rule))
            ->values()
            ->all();
    }

    public function createRule(int $businessId, User $user, int $boardId, array $data): array
    {
        $board = $this->boards->getBoard($businessId, $user, $boardId);
        $this->permission->userCanManageBoard($user, $board) || abort(403);

        $validated = $this->validatePayload($data);

        $rule = $this->rules->create([
            'business_id' => $businessId,
            'board_id' => $board->id,
            'created_by' => $user->id,
            'name' => $validated['name'],
            'trigger' => $validated['trigger'],
            'conditions' => $validated['conditions'] ?? null,
            'actions' => $validated['actions'],
            'is_active' => $validated['is_active'] ?? true,
        ]);

        return $this->serialize($rule->load(['creator:id,name,avatar']));
    }

    public function updateRule(int $businessId, User $user, int $ruleId, array $data): array
    {
        $rule = $this->findManaged($businessId, $user, $ruleId);

        $validated = $this->validatePayload($data, isUpdate: true);

        $rule = $this->rules->update($rule, $validated);

        return $this->serialize($rule->load(['creator:id,name,avatar']));
    }

    public function deleteRule(int $businessId, User $user, int $ruleId): void
    {
        $rule = $this->findManaged($businessId, $user, $ruleId);
        $this->rules->delete($rule);
    }

    public function toggleRule(int $businessId, User $user, int $ruleId, bool $active): array
    {
        $rule = $this->findManaged($businessId, $user, $ruleId);
        $rule = $this->rules->update($rule, [
            'is_active' => $active,
            'paused_at' => $active ? null : now(),
        ]);

        return $this->serialize($rule->load(['creator:id,name,avatar']));
    }

    public function serialize(PipelineAutomationRule $rule): array
    {
        return [
            'id' => $rule->id,
            'board_id' => $rule->board_id,
            'name' => $rule->name,
            'trigger' => $rule->trigger,
            'conditions' => $rule->conditions,
            'actions' => $rule->actions,
            'is_active' => (bool) $rule->is_active,
            'run_count' => (int) $rule->run_count,
            'last_run_at' => $rule->last_run_at?->toISOString(),
            'paused_at' => $rule->paused_at?->toISOString(),
            'created_by' => $rule->created_by,
            'creator' => $rule->creator ? ['id' => $rule->creator->id, 'name' => $rule->creator->name, 'avatar' => $rule->creator->avatar] : null,
            'created_at' => $rule->created_at?->toISOString(),
            'updated_at' => $rule->updated_at?->toISOString(),
        ];
    }

    protected function findManaged(int $businessId, User $user, int $ruleId): PipelineAutomationRule
    {
        $rule = $this->rules->find($ruleId);
        if (! $rule || (int) $rule->business_id !== $businessId) {
            abort(404, 'Automation rule not found.');
        }
        $board = $this->boards->getBoard($businessId, $user, (int) $rule->board_id);
        $this->permission->userCanManageBoard($user, $board) || abort(403);

        return $rule;
    }

    /** @return array{name: string, trigger: array, conditions?: ?array, actions: array, is_active?: bool} */
    protected function validatePayload(array $data, bool $isUpdate = false): array
    {
        $out = [];

        if (array_key_exists('name', $data) || ! $isUpdate) {
            $out['name'] = trim((string) ($data['name'] ?? ''));
            if ($out['name'] === '') {
                abort(422, 'Automation name is required.');
            }
        }

        if (array_key_exists('trigger', $data) || ! $isUpdate) {
            $trigger = $data['trigger'] ?? null;
            if (! is_array($trigger) || empty($trigger['type'])) {
                abort(422, 'A trigger type is required.');
            }
            $out['trigger'] = $trigger;
        }

        if (array_key_exists('actions', $data) || ! $isUpdate) {
            $actions = $data['actions'] ?? null;
            if (! is_array($actions) || $actions === []) {
                abort(422, 'At least one action is required.');
            }
            $out['actions'] = $actions;
        }

        if (array_key_exists('conditions', $data)) {
            $out['conditions'] = ($data['conditions'] === null || $data['conditions'] === [])
                ? null
                : $data['conditions'];
        }

        if (array_key_exists('is_active', $data)) {
            $out['is_active'] = (bool) $data['is_active'];
        }

        return $out;
    }
}