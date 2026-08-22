<?php

declare(strict_types=1);

namespace App\Services\Pipeline;

use App\Models\PipelineAutomationRule;
use App\Models\PipelineBoard;
use App\Models\PipelineLead;
use App\Models\User;

/**
 * Executes the actions of an automation rule against a lead (or creates a new
 * lead for create_card / recurring rules). Uses existing board/lead services so
 * every side effect (activity feed, conversations, stock/permissions) is real.
 */
class PipelineAutomationActionService
{
    public function __construct(
        protected PipelineLeadService $leads,
        protected PipelineBoardPermissionService $permission,
        protected PipelineBoardConversationService $conversation,
        protected PipelineBoardActivityService $activity,
        protected PipelineLabelService $labels,
    ) {}

    /**
     * Execute all actions of a rule. Returns true if at least one action ran.
     *
     * @return array{executed: int, created_lead_id?: int}
     */
    public function execute(
        PipelineAutomationRule $rule,
        PipelineBoard $board,
        ?PipelineLead $lead,
        ?User $actor = null,
    ): array {
        $systemUser = $actor ?? User::query()->find($board->created_by);
        if (! $systemUser) {
            return ['executed' => 0];
        }

        $executed = 0;
        $createdLeadId = null;

        foreach ($rule->actions as $action) {
            $type = (string) ($action['type'] ?? '');

            if ($type === 'create_card' || $type === 'create_task') {
                $newLead = $this->createCard($rule, $board, $systemUser, $action);
                if ($newLead) {
                    $executed++;
                    $createdLeadId = (int) $newLead->id;
                }
                continue;
            }

            if ($lead === null) {
                continue;
            }

            $executed += (int) $this->executeLeadAction($type, $rule, $board, $lead, $systemUser, $action);
        }

        return ['executed' => $executed, 'created_lead_id' => $createdLeadId];
    }

    protected function executeLeadAction(string $type, PipelineAutomationRule $rule, PipelineBoard $board, PipelineLead $lead, User $actor, array $action): bool
    {
        return match ($type) {
            'move_to_stage' => $this->moveToStage($board, $lead, $actor, $action),
            'assign_to' => $this->assignTo($board, $lead, $actor, $action),
            'add_label' => $this->addLabel($board, $lead, $action),
            'remove_label' => $this->removeLabel($board, $lead, $action),
            'set_priority' => $this->setPriority($board, $lead, $action),
            'set_due_date' => $this->setDueDate($board, $lead, $action),
            'set_field' => $this->setMetaField($board, $lead, $action),
            'post_conversation' => $this->postConversation($board, $lead, $actor, $action),
            'notify' => $this->notify($board, $lead, $actor, $action, email: false),
            'notify_email' => $this->notify($board, $lead, $actor, $action, email: true),
            'convert_to_customer' => $this->convert($board, $lead, $actor),
            'copy_card' => $this->copyCard($board, $lead, $actor, $action),
            'archive' => $this->archive($board, $lead, $actor),
            'create_checklist' => $this->createChecklist($lead, $action),
            'webhook' => $this->callWebhook($rule, $board, $lead, $action),
            default => false,
        };
    }

    protected function moveToStage(PipelineBoard $board, PipelineLead $lead, User $actor, array $action): bool
    {
        $stageId = (int) ($action['stage_id'] ?? 0);
        if ($stageId <= 0 || (int) $lead->stage_id === $stageId) {
            return false;
        }
        $stage = \App\Models\PipelineStage::query()->where('board_id', $board->id)->find($stageId);
        if (! $stage) {
            return false;
        }

        $this->leads->moveLead((int) $board->business_id, $actor, (int) $lead->id, $stageId, (float) $lead->position);

        return true;
    }

    protected function assignTo(PipelineBoard $board, PipelineLead $lead, User $actor, array $action): bool
    {
        $userId = (int) ($action['user_id'] ?? 0);
        if ($userId <= 0 || (int) $lead->assigned_to === $userId) {
            return false;
        }

        $user = User::query()->where('business_id', $board->business_id)->find($userId);
        if (! $user) {
            return false;
        }

        $lead->update(['assigned_to' => $userId]);
        $this->leads->recordActivity($lead, (int) $actor->id, 'assignee_change', "Automation assigned to {$user->name}");

        return true;
    }

    protected function addLabel(PipelineBoard $board, PipelineLead $lead, array $action): bool
    {
        $labelId = (int) ($action['label_id'] ?? 0);
        if ($labelId <= 0) {
            return false;
        }
        if ($lead->labels()->where('pipeline_labels.id', $labelId)->exists()) {
            return false;
        }
        $lead->labels()->attach($labelId);

        return true;
    }

    protected function removeLabel(PipelineBoard $board, PipelineLead $lead, array $action): bool
    {
        $labelId = (int) ($action['label_id'] ?? 0);
        if ($labelId <= 0) {
            return false;
        }
        $lead->labels()->detach($labelId);

        return true;
    }

    protected function setPriority(PipelineBoard $board, PipelineLead $lead, array $action): bool
    {
        $priority = (string) ($action['priority'] ?? '');
        if (! in_array($priority, ['low', 'medium', 'high', 'urgent'], true)) {
            return false;
        }
        if ((string) $lead->priority === $priority) {
            return false;
        }
        $lead->update(['priority' => $priority]);

        return true;
    }

    protected function setDueDate(PipelineBoard $board, PipelineLead $lead, array $action): bool
    {
        $offset = (int) ($action['offset_days'] ?? 0);
        if ($offset === 0 && empty($action['date'])) {
            return false;
        }

        $date = empty($action['date'])
            ? now()->addDays($offset)
            : \Illuminate\Support\Carbon::parse($action['date']);

        $lead->update(['due_date' => $date]);

        return true;
    }

    protected function setMetaField(PipelineBoard $board, PipelineLead $lead, array $action): bool
    {
        $metaFieldId = (int) ($action['meta_field_id'] ?? 0);
        if ($metaFieldId <= 0 || ! array_key_exists('value', $action)) {
            return false;
        }

        \App\Models\PipelineLeadMetaValue::updateOrCreate(
            ['lead_id' => $lead->id, 'meta_field_id' => $metaFieldId],
            ['value' => (string) $action['value']],
        );

        return true;
    }

    protected function postConversation(PipelineBoard $board, PipelineLead $lead, User $actor, array $action): bool
    {
        $body = $this->interpolate((string) ($action['body'] ?? ''), $board, $lead);
        if ($body === '') {
            return false;
        }

        $this->conversation->storeSystemMessage((int) $board->business_id, $actor, (int) $board->id, $body);

        return true;
    }

    protected function notify(PipelineBoard $board, PipelineLead $lead, User $actor, array $action, bool $email): bool
    {
        $message = $this->interpolate((string) ($action['message'] ?? ''), $board, $lead);
        $recipientId = (int) ($action['user_id'] ?? $lead->assigned_to ?? 0);

        if ($message === '' || $recipientId <= 0) {
            return false;
        }

        $recipient = User::query()->where('business_id', $board->business_id)->find($recipientId);
        if (! $recipient) {
            return false;
        }

        app(\App\Services\Pipeline\PipelineNotificationService::class)->notifyAutomation(
            $recipient,
            $lead,
            $board,
            $message,
            $email,
        );

        return true;
    }

    protected function convert(PipelineBoard $board, PipelineLead $lead, User $actor): bool
    {
        if ((string) $lead->status === 'converted') {
            return false;
        }
        $this->leads->convertLead((int) $board->business_id, $actor, (int) $lead->id, []);

        return true;
    }

    protected function copyCard(PipelineBoard $board, PipelineLead $lead, User $actor, array $action): bool
    {
        $targetStageId = (int) ($action['stage_id'] ?? $lead->stage_id);

        $copy = PipelineLead::create([
            'business_id' => $board->business_id,
            'board_id' => $board->id,
            'stage_id' => $targetStageId ?: $lead->stage_id,
            'created_by' => $actor->id,
            'assigned_to' => $lead->assigned_to,
            'customer_id' => $lead->customer_id,
            'title' => ($action['title'] ?? $lead->title),
            'card_type' => $lead->card_type ?? 'lead',
            'description' => $lead->description,
            'contact_name' => $lead->contact_name,
            'contact_email' => $lead->contact_email,
            'contact_phone' => $lead->contact_phone,
            'estimated_value' => $lead->estimated_value,
            'currency' => $lead->currency,
            'status' => 'open',
            'priority' => $lead->priority,
            'start_date' => $lead->start_date,
            'due_date' => $lead->due_date,
        ]);

        $this->leads->recordActivity($copy, (int) $actor->id, 'system', "Copied from {$lead->title} by automation");

        return true;
    }

    protected function archive(PipelineBoard $board, PipelineLead $lead, User $actor): bool
    {
        if ((string) $lead->status === 'archived') {
            return false;
        }
        $this->leads->archiveLead((int) $board->business_id, $actor, (int) $lead->id);

        return true;
    }

    /** @param  array<string, mixed>  $action */
    protected function createChecklist(PipelineLead $lead, array $action): bool
    {
        $title = trim((string) ($action['title'] ?? ''));
        if ($title === '') {
            return false;
        }

        $maxOrder = \App\Models\PipelineChecklist::query()->where('lead_id', $lead->id)->max('sort_order');

        \App\Models\PipelineChecklist::create([
            'lead_id' => $lead->id,
            'title' => $title,
            'description' => $action['description'] ?? null,
            'sort_order' => (int) ($maxOrder + 1),
        ]);

        return true;
    }

    /** @param  array<string, mixed>  $action */
    protected function callWebhook(PipelineAutomationRule $rule, PipelineBoard $board, PipelineLead $lead, array $action): bool
    {
        $url = trim((string) ($action['url'] ?? ''));
        if ($url === '' || ! filter_var($url, FILTER_VALIDATE_URL)) {
            return false;
        }

        $payload = [
            'event' => 'automation',
            'rule_id' => $rule->id,
            'rule_name' => $rule->name,
            'trigger' => (string) ($rule->trigger['type'] ?? ''),
            'board' => ['id' => $board->id, 'name' => $board->name],
            'card' => [
                'id' => $lead->id,
                'title' => $lead->title,
                'status' => $lead->status,
                'priority' => $lead->priority,
                'stage' => $lead->stage?->name,
                'assignee' => $lead->assignee?->name,
                'due_date' => $lead->due_date?->toISOString(),
                'estimated_value' => $lead->estimated_value,
                'contact_email' => $lead->contact_email,
                'contact_phone' => $lead->contact_phone,
            ],
            'sent_at' => now()->toIso8601String(),
        ];

        try {
            \Illuminate\Support\Facades\Http::timeout(8)->post($url, $payload);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('Automation webhook failed', [
                'rule_id' => $rule->id,
                'lead_id' => $lead->id,
                'url' => $url,
                'error' => $e->getMessage(),
            ]);
        }

        return true;
    }

    /** @param  array<string, mixed>  $action */
    protected function createCard(PipelineAutomationRule $rule, PipelineBoard $board, User $actor, array $action): ?PipelineLead
    {
        $stageId = (int) ($action['stage_id'] ?? $rule->trigger['stage_id'] ?? 0);
        $title = (string) ($action['title'] ?? $rule->name);

        $data = [
            'board_id' => $board->id,
            'title' => $title,
            'card_type' => (string) ($action['type'] === 'create_task' ? 'task' : 'lead'),
            'stage_id' => $stageId > 0 ? $stageId : null,
            'priority' => $action['priority'] ?? 'medium',
            'description' => $action['description'] ?? null,
            'assigned_to' => isset($action['user_id']) ? (int) $action['user_id'] : null,
            'due_date' => isset($action['offset_due_days']) ? now()->addDays((int) $action['offset_due_days']) : null,
            'estimated_value' => isset($action['estimated_value']) ? (float) $action['estimated_value'] : null,
        ];

        try {
            $lead = $this->leads->createLead((int) $board->business_id, $actor, $data, ['skip_automation' => true]);

            if (! empty($action['label_id'])) {
                $lead->labels()->attach((int) $action['label_id']);
            }

            return $lead;
        } catch (\Throwable) {
            return null;
        }
    }

    protected function interpolate(string $body, PipelineBoard $board, PipelineLead $lead): string
    {
        return str_replace(
            ['{card}', '{lead}', '{board}', '{column}', '{status}', '{assignee}'],
            [
                $lead->title,
                $lead->title,
                $board->name,
                $lead->stage?->name ?? '',
                $lead->status ?? '',
                $lead->assignee?->name ?? '',
            ],
            $body,
        );
    }
}