<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PipelineLeadResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $checklistTotal = 0;
        $checklistDone = 0;
        if ($this->relationLoaded('checklists')) {
            foreach ($this->checklists as $checklist) {
                if ($checklist->relationLoaded('items')) {
                    foreach ($checklist->items as $item) {
                        $checklistTotal++;
                        if ($item->is_done) {
                            $checklistDone++;
                        }
                    }
                }
            }
        }

        return [
            'id' => $this->id,
            'business_id' => $this->business_id,
            'board_id' => $this->board_id,
            'stage_id' => $this->stage_id,
            'title' => $this->title,
            'card_type' => $this->card_type ?? 'lead',
            'description' => $this->description,
            'contact_name' => $this->contact_name,
            'contact_email' => $this->contact_email,
            'contact_phone' => $this->contact_phone,
            'customer_id' => $this->customer_id,
            'converted_customer_id' => $this->converted_customer_id,
            'estimate_id' => $this->estimate_id,
            'assigned_to' => $this->assigned_to,
            'source_id' => $this->source_id,
            'estimated_value' => $this->estimated_value !== null ? (float) $this->estimated_value : null,
            'currency' => $this->currency,
            'status' => $this->status,
            'position' => (float) $this->position,
            'expected_close_date' => $this->expected_close_date?->toDateString(),
            'due_date' => $this->due_date?->toDateString(),
            'start_date' => $this->start_date?->toDateString(),
            'priority' => $this->priority,
            'background_color' => $this->background_color,
            'won_at' => $this->won_at?->toISOString(),
            'lost_at' => $this->lost_at?->toISOString(),
            'converted_at' => $this->converted_at?->toISOString(),
            'lost_reason' => $this->lost_reason,
            'checklist_total' => $checklistTotal > 0 ? $checklistTotal : ($this->checklist_total ?? null),
            'checklist_done' => $checklistTotal > 0 ? $checklistDone : ($this->checklist_done ?? null),
            'attachments_count' => $this->attachments_count ?? ($this->relationLoaded('attachments') ? $this->attachments->count() : null),
            'comments_count' => $this->comments_count ?? null,
            'created_by' => $this->created_by,
            'board' => $this->whenLoaded('board', fn () => [
                'id' => $this->board->id,
                'name' => $this->board->name,
            ]),
            'stage' => $this->whenLoaded('stage', fn () => [
                'id' => $this->stage->id,
                'name' => $this->stage->name,
                'color' => $this->stage->color,
                'is_won' => $this->stage->is_won,
                'is_lost' => $this->stage->is_lost,
            ]),
            'assignee' => $this->whenLoaded('assignee', fn () => $this->assignee ? [
                'id' => $this->assignee->id,
                'name' => $this->assignee->name,
                'avatar' => $this->assignee->avatar,
            ] : null),
            'assignees' => $this->whenLoaded('assignees', fn () => $this->assignees->map(fn ($u) => [
                'id' => $u->id,
                'name' => $u->name,
                'avatar' => $u->avatar,
            ])->values()),
            'creator' => $this->whenLoaded('creator', fn () => $this->creator ? [
                'id' => $this->creator->id,
                'name' => $this->creator->name,
                'avatar' => $this->creator->avatar,
            ] : null),
            'source' => $this->whenLoaded('source', fn () => $this->source ? [
                'id' => $this->source->id,
                'name' => $this->source->name,
            ] : null),
            'customer' => $this->whenLoaded('customer', fn () => $this->customer ? new CustomerResource($this->customer) : null),
            'converted_customer' => $this->whenLoaded('convertedCustomer', fn () => $this->convertedCustomer ? new CustomerResource($this->convertedCustomer) : null),
            'labels' => PipelineLabelResource::collection($this->whenLoaded('labels')),
            'checklists' => PipelineChecklistResource::collection($this->whenLoaded('checklists')),
            'attachments' => PipelineAttachmentResource::collection($this->whenLoaded('attachments')),
            'activities' => PipelineLeadActivityResource::collection($this->whenLoaded('activities')),
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
