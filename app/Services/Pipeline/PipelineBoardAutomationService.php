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
            ->with(['triggerStage:id,name', 'creator:id,name,avatar'])
            ->orderBy('name')
            ->get()
            ->map(fn (PipelineBoardAutomation $item) => $this->serializeAutomation($item))
            ->all();
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
}
