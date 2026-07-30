<?php

declare(strict_types=1);

namespace App\Services\Pipeline;

use App\Models\PipelineChecklist;
use App\Models\PipelineChecklistItem;
use App\Models\PipelineLeadActivity;
use App\Models\User;
use App\Services\Pipeline\PipelineBoardLookupService;
use App\Services\Pipeline\PipelineBoardPermissionService;

class PipelineChecklistService
{
    public function __construct(
        protected PipelineBoardLookupService $lookup,
        protected PipelineBoardPermissionService $permission,
    ) {}

    public function createChecklist(int $businessId, User $user, int $leadId, array $data): PipelineChecklist
    {
        $lead = $this->lookup->findLeadForUser($user, $leadId);
        $this->permission->assertCanEditBoard($user, $lead->board);

        $maxOrder = PipelineChecklist::query()->where('lead_id', $leadId)->max('sort_order');
        $title = $data['title'] ?? 'Checklist';

        $checklist = PipelineChecklist::create([
            'lead_id' => $leadId,
            'title' => $title,
            'description' => $data['description'] ?? null,
            'sort_order' => (int) ($data['sort_order'] ?? ($maxOrder + 1)),
        ]);

        $this->recordActivity($lead, $user->id, 'system', "Checklist added: {$title}", [
            'action' => 'checklist_added',
            'title' => $title,
        ]);

        return $checklist;
    }

    public function updateChecklist(int $businessId, User $user, int $checklistId, array $data): PipelineChecklist
    {
        $checklist = PipelineChecklist::query()->with('lead.board')->findOrFail($checklistId);
        $this->permission->assertCanEditBoard($user, $checklist->lead->board);

        $payload = [];
        if (array_key_exists('title', $data)) {
            $payload['title'] = $data['title'];
        }
        if (array_key_exists('description', $data)) {
            $payload['description'] = $data['description'];
        }
        if (array_key_exists('sort_order', $data)) {
            $payload['sort_order'] = $data['sort_order'];
        }
        $checklist->update($payload);

        return $checklist->fresh('items');
    }

    public function deleteChecklist(int $businessId, User $user, int $checklistId): void
    {
        $checklist = PipelineChecklist::query()->with('lead.board')->findOrFail($checklistId);
        $this->permission->assertCanEditBoard($user, $checklist->lead->board);
        $title = $checklist->title;

        $checklist->delete();

        $this->recordActivity($checklist->lead, $user->id, 'system', "Checklist removed: {$title}", [
            'action' => 'checklist_removed',
            'title' => $title,
        ]);
    }

    public function createChecklistItem(int $businessId, User $user, int $checklistId, array $data): PipelineChecklistItem
    {
        $checklist = PipelineChecklist::query()->with('lead.board')->findOrFail($checklistId);
        $this->permission->assertCanEditBoard($user, $checklist->lead->board);

        $maxOrder = PipelineChecklistItem::query()->where('checklist_id', $checklistId)->max('sort_order');
        $title = $data['title'];

        $item = PipelineChecklistItem::create([
            'checklist_id' => $checklistId,
            'title' => $title,
            'description' => $data['description'] ?? null,
            'is_done' => (bool) ($data['is_done'] ?? false),
            'sort_order' => (int) ($data['sort_order'] ?? ($maxOrder + 1)),
        ]);

        $this->recordActivity($checklist->lead, $user->id, 'system', "Checklist item added: {$title}", [
            'action' => 'checklist_item_added',
            'title' => $title,
        ]);

        return $item;
    }

    public function updateChecklistItem(int $businessId, User $user, int $itemId, array $data): PipelineChecklistItem
    {
        $item = PipelineChecklistItem::query()
            ->with('checklist.lead.board')
            ->findOrFail($itemId);
        $this->permission->assertCanEditBoard($user, $item->checklist->lead->board);

        $wasDone = (bool) $item->is_done;

        $payload = [];
        if (array_key_exists('title', $data)) {
            $payload['title'] = $data['title'];
        }
        if (array_key_exists('description', $data)) {
            $payload['description'] = $data['description'];
        }
        if (array_key_exists('is_done', $data)) {
            $payload['is_done'] = (bool) $data['is_done'];
        }
        if (array_key_exists('sort_order', $data)) {
            $payload['sort_order'] = $data['sort_order'];
        }
        $item->update($payload);

        if (array_key_exists('is_done', $data) && (bool) $data['is_done'] !== $wasDone) {
            $message = (bool) $data['is_done']
                ? "Checklist item completed: {$item->title}"
                : "Checklist item reopened: {$item->title}";
            $this->recordActivity($item->checklist->lead, $user->id, 'system', $message, [
                'action' => (bool) $data['is_done'] ? 'checklist_item_done' : 'checklist_item_reopened',
                'title' => $item->title,
            ]);
        }

        return $item->fresh();
    }

    public function deleteChecklistItem(int $businessId, User $user, int $itemId): void
    {
        $item = PipelineChecklistItem::query()
            ->with('checklist.lead.board')
            ->findOrFail($itemId);
        $this->permission->assertCanEditBoard($user, $item->checklist->lead->board);
        $title = $item->title;
        $lead = $item->checklist->lead;

        $item->delete();

        $this->recordActivity($lead, $user->id, 'system', "Checklist item removed: {$title}", [
            'action' => 'checklist_item_removed',
            'title' => $title,
        ]);
    }

    protected function recordActivity(
        PipelineLead $lead,
        ?int $userId,
        string $type,
        ?string $body,
        ?array $metadata = null,
        ?int $parentId = null,
    ): PipelineLeadActivity {
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
}
