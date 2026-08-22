<?php

declare(strict_types=1);

namespace App\Services\Pipeline;

use App\Models\PipelineLead;
use App\Models\PipelineLeadActivity;
use App\Models\PipelineLeadAssignee;
use App\Models\User;
use App\Services\CustomerContactService;
use App\Services\Pipeline\Concerns\PipelineLeadUpdateHelpers;
use App\Services\Pipeline\PipelineBoardAutomationService;
use App\Services\Pipeline\PipelineBoardActivityService;
use App\Services\Pipeline\PipelineBoardLookupService;
use App\Services\Pipeline\PipelineBoardPermissionService;
use App\Services\Pipeline\PipelineNotificationService;
use App\Services\Pipeline\PipelineAutomationEventDispatcher;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Validation\ValidationException;

class PipelineLeadService
{
    use PipelineLeadUpdateHelpers;

    public function __construct(
        protected PipelineBoardLookupService $lookup,
        protected PipelineBoardPermissionService $permission,
        protected PipelineNotificationService $pipelineNotifier,
        protected PipelineBoardActivityService $boardActivity,
        protected PipelineBoardAutomationService $boardAutomations,
        protected CustomerContactService $customerContactService,
        protected PipelineAutomationEventDispatcher $eventAutomations,
    ) {}

    public function listLeads(int $businessId, User $user, array $filters = []): Collection
    {
        $query = PipelineLead::query()
            ->with(['board', 'stage', 'assignee:id,name,avatar', 'source:id,name', 'customer:id,name,email,phone', 'meetings']);

        if (!empty($filters['board_id'])) {
            $board = $this->lookup->findBoardForUser($user, (int) $filters['board_id']);
            $this->permission->assertCanViewBoard($user, $board);
            $query->where('board_id', $board->id);
        }

        if (!empty($filters['stage_id'])) {
            $query->where('stage_id', (int) $filters['stage_id']);
        }

        if (!empty($filters['assignee_id'])) {
            $query->whereHas('assignees', fn ($q) => $q->where('users.id', (int) $filters['assignee_id']));
        }

        if (!empty($filters['assigned_to'])) {
            $query->where('assigned_to', (int) $filters['assigned_to']);
        }

        if (!empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        if (!empty($filters['source_id'])) {
            $query->where('source_id', (int) $filters['source_id']);
        }

        if (!empty($filters['label_id'])) {
            $query->whereHas('labels', fn ($q) => $q->where('pipeline_labels.id', (int) $filters['label_id']));
        }

        if (!empty($filters['priority'])) {
            $query->where('priority', $filters['priority']);
        }

        if (!empty($filters['search'])) {
            $search = $filters['search'];
            $query->where(function ($q) use ($search) {
                $q->where('title', 'like', "%{$search}%")
                    ->orWhere('contact_name', 'like', "%{$search}%")
                    ->orWhere('contact_email', 'like', "%{$search}%")
                    ->orWhere('contact_phone', 'like', "%{$search}%");
            });
        }

        if (!empty($filters['date_from'])) {
            $query->whereDate('created_at', '>=', $filters['date_from']);
        }

        if (!empty($filters['date_to'])) {
            $query->whereDate('created_at', '<=', $filters['date_to']);
        }

        if (!empty($filters['expected_close_from'])) {
            $query->whereDate('expected_close_date', '>=', $filters['expected_close_from']);
        }

        if (!empty($filters['expected_close_to'])) {
            $query->whereDate('expected_close_date', '<=', $filters['expected_close_to']);
        }

        if (!empty($filters['created_by'])) {
            $query->where('created_by', (int) $filters['created_by']);
        }

        if (!empty($filters['recent'])) {
            $query->where('updated_at', '>=', now()->subDays((int) $filters['recent']));
        }

        if (!empty($filters['favorites'])) {
            $query->whereHas('bookmarks', fn ($q) => $q->where('user_id', $user->id));
        }

        if (!empty($filters['card_type'])) {
            $query->where('card_type', $filters['card_type']);
        }

        $boardIds = $this->listAccessibleBoardIds($businessId, $user);
        $query->whereIn('board_id', $boardIds);

        $sortField = $filters['sort'] ?? 'created_at';
        $sortDir = $filters['order'] ?? 'desc';

        if ($sortField === 'stage_order') {
            $query->join('pipeline_stages', 'pipeline_leads.stage_id', '=', 'pipeline_stages.id')
                ->orderBy('pipeline_stages.sort_order', $sortDir)
                ->select('pipeline_leads.*');
        } else {
            $query->orderBy($sortField, $sortDir);
        }

        return $query->get();
    }

    public function createLead(int $businessId, User $user, array $data, array $options = []): PipelineLead
    {
        $boardId = (int) ($data['board_id'] ?? throw ValidationException::withMessages(['board_id' => 'The board_id field is required.']));
        $board = $this->lookup->findBoardForUser($user, $boardId);
        $this->permission->assertCanEditBoard($user, $board);
        $board->loadMissing('business');

        if (empty($data['stage_id'])) {
            $stage = $board->stages()->orderBy('sort_order')->first();
            $stageId = $stage?->id;
        } else {
            $stageId = (int) $data['stage_id'];
        }

        $lead = PipelineLead::query()->create([
            'business_id' => $board->business_id,
            'board_id' => $board->id,
            'stage_id' => $stageId,
            'created_by' => $user->id,
            'title' => $data['title'],
            'card_type' => $data['card_type'] ?? 'lead',
            'description' => $data['description'] ?? null,
            'contact_name' => $data['contact_name'] ?? null,
            'contact_email' => $data['contact_email'] ?? null,
            'contact_phone' => $data['contact_phone'] ?? null,
            'customer_id' => $data['customer_id'] ?? null,
            'source_id' => $data['source_id'] ?? null,
            'estimated_value' => $data['estimated_value'] ?? null,
            'currency' => $data['currency'] ?? $board->business?->currency ?? 'UGX',
            'expected_close_date' => $data['expected_close_date'] ?? null,
            'due_date' => $data['due_date'] ?? null,
            'start_date' => $data['start_date'] ?? null,
            'priority' => $data['priority'] ?? null,
            'status' => $data['status'] ?? 'open',
            'assigned_to' => $data['assigned_to'] ?? null,
            'stage_entered_at' => now(),
        ]);

        $lead->load('board', 'stage');

        $assigneeIds = $this->resolveAssigneeIds($data, $user);
        if ($assigneeIds !== []) {
            $this->syncLeadAssignees($lead, $assigneeIds, $user->id);
        }

        if (!empty($data['label_ids'])) {
            $lead->labels()->sync($data['label_ids']);
        }

        $lead->refresh();
        $lead->load('labels', 'assignees');

        $this->recordActivity($lead, $user->id, 'system', ($data['card_type'] ?? 'lead') === 'card' ? 'Card created' : 'Lead created');

        if (empty($options['skip_automation'])) {
            $this->eventAutomations->cardCreated($lead, $user);
        }

        return $this->loadLeadWithHistory($lead);
    }

    public function getLead(int $businessId, User $user, int $leadId): PipelineLead
    {
        $lead = $this->lookup->findLeadForUser($user, $leadId);
        $this->permission->assertCanViewBoard($user, $lead->board);

        return $lead->load(array_merge($this->leadDetailRelations(), [
            'activities' => fn ($q) => $q->with(['user:id,name,avatar', 'reactions'])->orderBy('created_at'),
        ]));
    }

    public function updateLead(int $businessId, User $user, int $leadId, array $data): PipelineLead
    {
        $lead = $this->lookup->findLeadForUser($user, $leadId);
        $this->permission->assertCanEditBoard($user, $lead->board);
        $lead->load(['labels:id,name', 'assignees:id,name']);

        $before = $lead->replicate();
        $before->setRelation('labels', $lead->labels);
        $before->setRelation('assignees', $lead->assignees);

        $updates = [];
        foreach ([
            'title', 'card_type', 'description', 'assigned_to', 'customer_id', 'source_id',
            'contact_name', 'contact_email', 'contact_phone', 'estimated_value',
            'currency', 'expected_close_date', 'due_date', 'start_date', 'priority',
            'background_color', 'lost_reason', 'status', 'is_pinned',
        ] as $field) {
            if (array_key_exists($field, $data)) {
                $updates[$field] = $data[$field];
            }
        }

        if (array_key_exists('status', $data)) {
            $status = $data['status'];
            if ($status === 'won') {
                $updates['won_at'] = now();
                $updates['lost_at'] = null;
            } elseif ($status === 'lost') {
                $updates['lost_at'] = now();
                $updates['won_at'] = null;
            } elseif ($status === 'open') {
                $updates['won_at'] = null;
                $updates['lost_at'] = null;
            }
        }

        if ($updates !== []) {
            $lead->update($updates);
        }

        if (array_key_exists('label_ids', $data)) {
            $beforeLabelIds = $lead->labels()->pluck('pipeline_labels.id')->map(fn ($id) => (int) $id)->all();
            $lead->labels()->sync($data['label_ids'] ?? []);
            $afterLabelIds = array_map('intval', $data['label_ids'] ?? []);
            $added = array_diff($afterLabelIds, $beforeLabelIds);
            if ($added !== []) {
                $lead->load('board');
                $this->eventAutomations->labelAdded($lead, $user);
            }
        }

        if ($updates !== [] || array_key_exists('label_ids', $data)) {
            $lead->load('board');
            $this->eventAutomations->fieldChanged($lead, $user);
        }

        if (array_key_exists('assignee_ids', $data) || array_key_exists('assigned_to', $data)) {
            $assigneeIds = $this->resolveAssigneeIds($data, $user, $lead);
            $newAssignees = $this->syncLeadAssignees($lead, $assigneeIds, $user->id);
            if ($newAssignees !== []) {
                $lead->load('board');
                $this->pipelineNotifier->notifyAssignees(
                    $lead,
                    $lead->board,
                    $user,
                    User::query()->whereIn('id', $newAssignees)->get()->all(),
                    false,
                );
                $this->eventAutomations->assigned($lead, $user);
            }
        }

        $lead->refresh();
        $this->recordLeadUpdateActivities($lead, $user, $before, $data);

        if (array_key_exists('status', $data)) {
            $newStatus = (string) ($lead->status ?? 'open');
            $oldStatus = (string) ($before->status ?? 'open');
            if ($newStatus !== $oldStatus && in_array($newStatus, ['won', 'lost'], true)) {
                $lead->load('board');
                $this->boardAutomations->runForLeadStatusChange($lead, $lead->board, $newStatus, $user);
                $this->boardActivity->log(
                    $lead->board,
                    $user,
                    'lead_status',
                    "Marked {$lead->title} as ".strtoupper($newStatus),
                    null,
                    'lead',
                    (int) $lead->id,
                    ['status' => $newStatus],
                );
                $this->eventAutomations->statusChanged($lead, $user);
            }
        }

        return $this->loadLeadWithHistory($lead);
    }

    public function moveLead(int $businessId, User $user, int $leadId, int $stageId, float $position): PipelineLead
    {
        $lead = $this->lookup->findLeadForUser($user, $leadId);
        $this->permission->assertCanEditBoard($user, $lead->board);

        $stage = $this->lookup->findStageForUser($user, $stageId);

        $fromStage = $lead->stage;
        $fromName = $fromStage?->name ?? 'Unknown';

        $lead->update([
            'stage_id' => $stage->id,
            'position' => $position,
            'stage_entered_at' => now(),
            'updated_at' => now(),
        ]);

        if ($fromStage && $stage->name !== $fromName) {
            $this->recordActivity($lead, $user->id, 'stage_change', "Moved from {$fromName} to {$stage->name}", [
                'action' => 'stage_change',
                'from_stage_id' => $fromStage->id,
                'from_stage' => $fromName,
                'to_stage_id' => $stage->id,
                'to_stage' => $stage->name,
            ]);

            if ($fromStage->is_won || $fromStage->is_lost) {
                $lead->update(['status' => 'open']);
            }

            if ($stage->is_won) {
                $lead->update(['status' => 'won', 'won_at' => now()]);
                $this->recordActivity($lead, $user->id, 'system', 'Lead won!');
            } elseif ($stage->is_lost) {
                $lead->update(['status' => 'lost', 'lost_at' => now()]);
                $this->recordActivity($lead, $user->id, 'system', 'Lead lost');
            }

            if ($lead->project_task_id) {
                $this->syncTaskStatusFromStage($lead, $stage);
            }
        }

        $lead->load('board');
        $this->boardAutomations->runForLeadStageChange($lead, $lead->board, $stage, $user);
        $this->boardActivity->log(
            $lead->board,
            $user,
            'lead_moved',
            "{$lead->title} moved to {$stage->name}",
            null,
            'lead',
            (int) $lead->id,
            ['from' => $fromName, 'to' => $stage->name, 'stage_id' => $stage->id],
        );

        $lead->load('stage');
        $this->eventAutomations->stageEntered($lead, $user);

        return $lead->fresh($this->leadDetailRelations());
    }

    public function convertLead(int $businessId, User $user, int $leadId, array $data): PipelineLead
    {
        $lead = $this->lookup->findLeadForUser($user, $leadId);
        $this->permission->assertCanEditBoard($user, $lead->board);

        $customer = $this->customerContactService->resolve($lead->business_id, [
            'name' => $data['name'] ?? $lead->contact_name,
            'email' => $data['email'] ?? $lead->contact_email,
            'phone' => $data['phone'] ?? $lead->contact_phone,
        ]);

        $lead->update([
            'converted_to_customer_id' => $customer->id,
            'status' => 'converted',
        ]);

        $this->recordActivity($lead, $user->id, 'system', 'Lead converted to customer', [
            'action' => 'converted',
            'customer_id' => $customer->id,
        ]);

        $lead->load('board', 'stage');
        $this->eventAutomations->statusChanged($lead, $user);
        $this->eventAutomations->converted($lead, $user);

        return $lead->fresh($this->leadDetailRelations());
    }

    public function archiveLead(int $businessId, User $user, int $leadId): void
    {
        $lead = $this->lookup->findLeadForUser($user, $leadId);
        $this->permission->assertCanEditBoard($user, $lead->board);

        $lead->update(['status' => 'archived']);

        $this->recordActivity($lead, $user->id, 'system', 'Card archived', [
            'action' => 'archived',
        ]);
    }

    public function recordActivity(PipelineLead $lead, int $userId, string $type, ?string $body, ?array $metadata = null, ?int $parentId = null): PipelineLeadActivity
    {
        return PipelineLeadActivity::create([
            'business_id' => $lead->business_id,
            'lead_id' => $lead->id,
            'parent_id' => $parentId,
            'user_id' => $userId,
            'type' => $type,
            'body' => $body,
            'metadata' => $metadata,
        ]);
    }

    public function logLeadHistoryEvent(PipelineLead $lead, User $user, string $body, ?array $metadata = null): PipelineLeadActivity
    {
        return $this->recordActivity($lead, $user->id, 'system', $body, $metadata);
    }
}
