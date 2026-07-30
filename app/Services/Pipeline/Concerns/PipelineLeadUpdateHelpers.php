<?php

declare(strict_types=1);

namespace App\Services\Pipeline\Concerns;

use App\Models\PipelineBoard;
use App\Models\PipelineLead;
use App\Models\PipelineStage;
use App\Models\ProjectTask;
use App\Models\User;

trait PipelineLeadUpdateHelpers
{
    protected function recordLeadUpdateActivities(PipelineLead $lead, User $user, PipelineLead $before, array $data): void
    {
        $changes = [];
        $fields = [
            'title', 'description', 'contact_name', 'contact_email', 'contact_phone',
            'estimated_value', 'currency', 'expected_close_date', 'due_date', 'start_date',
            'priority', 'lost_reason', 'card_type',
        ];
        foreach ($fields as $field) {
            $oldVal = $this->normalizeActivityValue($before->$field ?? null);
            $newVal = $this->normalizeActivityValue($lead->$field ?? null);
            if ($oldVal !== $newVal && array_key_exists($field, $data)) {
                $changes[$field] = ['from' => $oldVal, 'to' => $newVal];
            }
        }

        if (array_key_exists('status', $data)) {
            $oldStatus = $before->status ?? 'open';
            $newStatus = $lead->status ?? 'open';
            if ($oldStatus !== $newStatus) {
                $changes['status'] = ['from' => $oldStatus, 'to' => $newStatus];
                if ($newStatus === 'won') {
                    $this->recordActivity($lead, $user->id, 'system', 'Lead won!', [
                        'action' => 'won', 'value' => $lead->estimated_value,
                    ]);
                } elseif ($newStatus === 'lost') {
                    $reason = $lead->lost_reason ?? 'No reason given';
                    $this->recordActivity($lead, $user->id, 'system', "Lead lost: {$reason}", [
                        'action' => 'lost', 'reason' => $lead->lost_reason,
                    ]);
                }
            }
        }

        if (array_key_exists('customer_id', $data) && $lead->customer_id) {
            $this->recordActivity($lead, $user->id, 'system', 'Customer linked');
        }

        if (array_key_exists('source_id', $data)) {
            $oldSource = $before->source_id;
            $newSource = $lead->source_id;
            if ($oldSource !== $newSource) {
                $sourceName = $lead->source?->name ?? 'Unknown';
                $this->recordActivity($lead, $user->id, 'system', "Source set to: {$sourceName}");
            }
        }

        if (array_key_exists('label_ids', $data)) {
            $oldLabels = $before->getRelation('labels')?->pluck('name')->implode(', ') ?: 'None';
            $newLabels = $lead->getRelation('labels')?->pluck('name')->implode(', ') ?: 'None';
            if ($oldLabels !== $newLabels) {
                $this->recordActivity($lead, $user->id, 'system', "Labels updated: {$newLabels}");
            }
        }

        if (array_key_exists('assignee_ids', $data) || array_key_exists('assigned_to', $data)) {
            $oldAssignees = $before->getRelation('assignees')?->pluck('name')->implode(', ') ?: 'None';
            $newAssignees = $lead->getRelation('assignees')?->pluck('name')->implode(', ') ?: 'None';
            if ($oldAssignees !== $newAssignees) {
                $this->recordActivity($lead, $user->id, 'system', "Assignees changed: {$newAssignees}");
            }
        }

        if (!empty($changes)) {
            $summary = [];
            foreach ($changes as $field => $vals) {
                $summary[] = "{$field}: {$vals['from']} → {$vals['to']}";
            }
            $this->recordActivity($lead, $user->id, 'system', 'Card updated', [
                'action' => 'updated',
                'changes' => $changes,
            ]);
        }
    }

    protected function leadDetailRelations(): array
    {
        return [
            'stage:id,name,color,is_won,is_lost,sort_order',
            'board:id,name,business_id,project_id,visibility,background_type,background_value,workspace,cover_color',
            'creator:id,name,avatar',
            'assignee:id,name,avatar',
            'assignees:id,name,avatar',
            'source:id,name',
            'customer:id,name,email,phone',
            'labels:id,name,color',
            'checklists.items',
            'attachments',
            'meetings',
            'reminders',
        ];
    }

    protected function loadLeadWithHistory(PipelineLead $lead): PipelineLead
    {
        return $lead->load(array_merge($this->leadDetailRelations(), [
            'activities' => fn ($q) => $q->with(['user:id,name,avatar'])->orderByDesc('created_at'),
        ]));
    }

    protected function statusChangeMessage(string $from, string $to, string $cardType): string
    {
        $label = $cardType === 'card' ? 'Card' : 'Lead';
        return match (true) {
            $from === 'open' && $to === 'won' => "{$label} marked as won",
            $from === 'open' && $to === 'lost' => "{$label} marked as lost",
            $from === 'won' && $to === 'open' => "{$label} reopened",
            $from === 'lost' && $to === 'open' => "{$label} reopened",
            $from === 'won' && $to === 'lost' => "{$label} moved from won to lost",
            $from === 'lost' && $to === 'won' => "{$label} moved from lost to won",
            default => "{$label} status changed from {$from} to {$to}",
        };
    }

    protected function normalizeActivityValue(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        if ($value instanceof \Carbon\CarbonInterface) {
            return $value->toDateString();
        }
        if (is_float($value) || is_int($value)) {
            return (string) $value;
        }
        return (string) $value;
    }

    protected function syncTaskStatusFromStage(PipelineLead $lead, PipelineStage $stage): void
    {
        $stageName = $stage->name;
        $statusMap = [
            'To Do' => 'todo',
            'In Progress' => 'in_progress',
            'Review' => 'in_progress',
            'Done' => 'done',
        ];
        $taskStatus = $statusMap[$stageName] ?? 'todo';

        ProjectTask::query()->where('id', $lead->project_task_id)->update(['status' => $taskStatus]);
    }

    protected function createTaskFromLead(PipelineLead $lead, int $businessId): void
    {
        $stageName = $lead->stage?->name;
        $statusMap = [
            'To Do' => 'todo',
            'In Progress' => 'in_progress',
            'Review' => 'in_progress',
            'Done' => 'done',
        ];
        $taskStatus = $statusMap[$stageName] ?? 'todo';

        $task = ProjectTask::create([
            'project_id' => $lead->board->project_id,
            'name' => $lead->title,
            'description' => $lead->description,
            'status' => $taskStatus,
            'estimated_hours' => 0,
            'actual_hours' => 0,
            'budget_cost' => 0,
            'assigned_to' => $lead->assigned_to,
        ]);

        $lead->update(['project_task_id' => $task->id]);
    }

    protected function resolveAssigneeIds(array $data, User $actor, ?PipelineLead $existing = null): array
    {
        if (array_key_exists('assignee_ids', $data)) {
            $ids = array_map('intval', (array) ($data['assignee_ids'] ?? []));
        } elseif (array_key_exists('assigned_to', $data)) {
            $assignedTo = $data['assigned_to'];
            $ids = $assignedTo ? [((int) $assignedTo)] : [];
        } else {
            return $existing
                ? ($existing->assignees->pluck('id')->toArray() ?: [])
                : [$actor->id];
        }

        return array_values(array_unique(array_filter($ids, fn ($id) => $id > 0)));
    }

    protected function syncLeadAssignees(PipelineLead $lead, array $userIds, int $assignedBy): array
    {
        $existingIds = $lead->assignees()->pluck('users.id')->map(fn ($id) => (int) $id)->toArray();
        $newIds = array_diff($userIds, $existingIds);

        $lead->assignees()->sync($userIds);

        if (!empty($userIds)) {
            $lead->assigned_to = $userIds[0];
            $lead->saveQuietly();
        }

        return array_values($newIds);
    }

    private function listAccessibleBoardIds(int $businessId, User $user): array
    {
        return PipelineBoard::query()
            ->where('is_archived', false)
            ->where(function ($q) use ($businessId, $user) {
                $q->where('business_id', $businessId)
                    ->orWhereHas('members', fn ($m) => $m->where('user_id', $user->id));
            })
            ->pluck('id')
            ->toArray();
    }
}
