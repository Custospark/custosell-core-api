<?php

declare(strict_types=1);

namespace App\Services\Pipeline;

use App\Models\PipelineBoard;
use App\Models\PipelineBoardAutomation;
use App\Models\PipelineLead;
use App\Models\PipelineStage;
use App\Models\User;
use App\Services\PipelineService;

class PipelineBoardAutomationService
{
    public function __construct(
        protected PipelineService $pipeline,
        protected PipelineBoardConversationService $conversation,
        protected PipelineBoardActivityService $activity,
    ) {}

    /** @return list<array<string, mixed>> */
    public function listAutomations(int $businessId, User $user, int $boardId): array
    {
        $board = $this->pipeline->getBoard($businessId, $user, $boardId);

        return PipelineBoardAutomation::query()
            ->where('board_id', $board->id)
            ->with(['triggerStage:id,name,sort_order,is_won,is_lost,color', 'creator:id,name,avatar'])
            ->get()
            ->sortBy(fn (PipelineBoardAutomation $item) => $item->triggerStage?->sort_order ?? 999)
            ->values()
            ->map(fn (PipelineBoardAutomation $item) => $this->serializeAutomation($item))
            ->all();
    }

    /**
     * Replace board automations with stage-scoped rules from board setup.
     *
     * @param  list<array{trigger_type: string, trigger_stage_id?: int|null, action_body: string, is_active?: bool}>  $rules
     * @return list<array<string, mixed>>
     */
    public function syncBoardAutomations(int $businessId, User $user, int $boardId, array $rules): array
    {
        $board = $this->pipeline->getBoard($businessId, $user, $boardId);
        $this->pipeline->userCanManageBoard($user, $board) || abort(403);

        $board->load('stages');
        $validStageIds = $board->stages->pluck('id')->map(fn ($id) => (int) $id)->all();

        PipelineBoardAutomation::query()->where('board_id', $board->id)->delete();

        $created = [];
        foreach ($rules as $rule) {
            if (! ($rule['is_active'] ?? true)) {
                continue;
            }

            $body = trim((string) ($rule['action_body'] ?? ''));
            if ($body === '') {
                continue;
            }

            $triggerType = (string) ($rule['trigger_type'] ?? 'stage_entered');
            $stageId = isset($rule['trigger_stage_id']) ? (int) $rule['trigger_stage_id'] : null;
            $stageName = null;

            if ($triggerType === 'stage_entered') {
                if (! $stageId || ! in_array($stageId, $validStageIds, true)) {
                    continue;
                }
                $stageName = $board->stages->firstWhere('id', $stageId)?->name;
            } elseif (! in_array($triggerType, ['status_won', 'status_lost'], true)) {
                continue;
            }

            $automation = PipelineBoardAutomation::create([
                'business_id' => $businessId,
                'board_id' => $board->id,
                'created_by' => $user->id,
                'name' => $this->automationName($triggerType, $stageName),
                'trigger_type' => $triggerType,
                'trigger_stage_id' => $triggerType === 'stage_entered' ? $stageId : null,
                'action_type' => 'conversation_post',
                'action_body' => $body,
                'is_active' => true,
            ]);

            $created[] = $this->serializeAutomation(
                $automation->load(['triggerStage:id,name,sort_order,is_won,is_lost,color', 'creator:id,name,avatar']),
            );
        }

        return $created;
    }

    /** @return array<string, mixed> */
    public function createAutomation(
        int $businessId,
        User $user,
        int $boardId,
        string $name,
        string $triggerType,
        string $actionType,
        string $actionBody,
        ?int $triggerStageId = null,
    ): array {
        $board = $this->pipeline->getBoard($businessId, $user, $boardId);
        $this->pipeline->userCanManageBoard($user, $board) || abort(403);

        $automation = PipelineBoardAutomation::create([
            'business_id' => $businessId,
            'board_id' => $board->id,
            'created_by' => $user->id,
            'name' => $name,
            'trigger_type' => $triggerType,
            'trigger_stage_id' => $triggerStageId,
            'action_type' => $actionType,
            'action_body' => $actionBody,
            'is_active' => true,
        ]);

        return $this->serializeAutomation($automation->load(['triggerStage:id,name', 'creator:id,name,avatar']));
    }

    public function deleteAutomation(int $businessId, User $user, int $automationId): void
    {
        $automation = PipelineBoardAutomation::query()
            ->where('business_id', $businessId)
            ->whereKey($automationId)
            ->firstOrFail();
        $board = $this->pipeline->getBoard($businessId, $user, (int) $automation->board_id);
        $this->pipeline->userCanManageBoard($user, $board) || abort(403);
        $automation->delete();
    }

    public function runForLeadStageChange(
        PipelineLead $lead,
        PipelineBoard $board,
        ?PipelineStage $stage,
        ?User $actor = null,
    ): void {
        $automations = PipelineBoardAutomation::query()
            ->where('board_id', $board->id)
            ->where('is_active', true)
            ->where('trigger_type', 'stage_entered')
            ->when($stage, fn ($q) => $q->where(function ($inner) use ($stage) {
                $inner->whereNull('trigger_stage_id')->orWhere('trigger_stage_id', $stage->id);
            }))
            ->get();

        foreach ($automations as $automation) {
            $this->executeAutomation($automation, $board, $lead, $actor, $stage?->name);
        }
    }

    public function runForLeadStatusChange(
        PipelineLead $lead,
        PipelineBoard $board,
        string $status,
        ?User $actor = null,
    ): void {
        $trigger = match ($status) {
            'won' => 'status_won',
            'lost' => 'status_lost',
            default => null,
        };
        if (! $trigger) {
            return;
        }

        $automations = PipelineBoardAutomation::query()
            ->where('board_id', $board->id)
            ->where('is_active', true)
            ->where('trigger_type', $trigger)
            ->get();

        foreach ($automations as $automation) {
            $this->executeAutomation($automation, $board, $lead, $actor, strtoupper($status));
        }
    }

    protected function executeAutomation(
        PipelineBoardAutomation $automation,
        PipelineBoard $board,
        PipelineLead $lead,
        ?User $actor,
        ?string $contextLabel,
    ): void {
        $body = str_replace(
            ['{card}', '{lead}', '{board}', '{column}', '{status}'],
            [$lead->title, $lead->title, $board->name, $contextLabel ?? '', $lead->status ?? ''],
            $automation->action_body,
        );

        $systemUser = $actor ?? User::query()->find($board->created_by);
        if (! $systemUser) {
            return;
        }

        if ($automation->action_type === 'conversation_post') {
            $this->conversation->storeSystemMessage((int) $board->business_id, $systemUser, (int) $board->id, $body);
        }

        $this->activity->log(
            $board,
            $systemUser,
            'automation',
            "Automation: {$automation->name}",
            $body,
            'lead',
            $lead->id,
            ['automation_id' => $automation->id],
        );
    }

    /** @return array<string, mixed> */
    protected function serializeAutomation(PipelineBoardAutomation $automation): array
    {
        return [
            'id' => $automation->id,
            'board_id' => $automation->board_id,
            'name' => $automation->name,
            'trigger_type' => $automation->trigger_type,
            'trigger_stage_id' => $automation->trigger_stage_id,
            'trigger_stage' => $automation->triggerStage ? [
                'id' => $automation->triggerStage->id,
                'name' => $automation->triggerStage->name,
                'sort_order' => $automation->triggerStage->sort_order,
                'is_won' => (bool) $automation->triggerStage->is_won,
                'is_lost' => (bool) $automation->triggerStage->is_lost,
                'color' => $automation->triggerStage->color,
            ] : null,
            'action_type' => $automation->action_type,
            'action_body' => $automation->action_body,
            'is_active' => $automation->is_active,
            'creator' => $automation->creator ? [
                'id' => $automation->creator->id,
                'name' => $automation->creator->name,
                'avatar' => $automation->creator->avatar,
            ] : null,
        ];
    }

    protected function automationName(string $triggerType, ?string $stageName): string
    {
        return match ($triggerType) {
            'status_won' => 'Notify when a card is won',
            'status_lost' => 'Notify when a card is lost',
            default => $stageName ? "Notify when entering {$stageName}" : 'Notify on column change',
        };
    }
}
