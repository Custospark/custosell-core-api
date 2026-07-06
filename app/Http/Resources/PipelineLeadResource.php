<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PipelineLeadResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'business_id' => $this->business_id,
            'board_id' => $this->board_id,
            'stage_id' => $this->stage_id,
            'title' => $this->title,
            'description' => $this->description,
            'contact_name' => $this->contact_name,
            'contact_email' => $this->contact_email,
            'contact_phone' => $this->contact_phone,
            'customer_id' => $this->customer_id,
            'converted_customer_id' => $this->converted_customer_id,
            'assigned_to' => $this->assigned_to,
            'source_id' => $this->source_id,
            'estimated_value' => $this->estimated_value !== null ? (float) $this->estimated_value : null,
            'currency' => $this->currency,
            'status' => $this->status,
            'position' => (float) $this->position,
            'expected_close_date' => $this->expected_close_date?->toDateString(),
            'won_at' => $this->won_at?->toISOString(),
            'lost_at' => $this->lost_at?->toISOString(),
            'converted_at' => $this->converted_at?->toISOString(),
            'lost_reason' => $this->lost_reason,
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
            ] : null),
            'source' => $this->whenLoaded('source', fn () => $this->source ? [
                'id' => $this->source->id,
                'name' => $this->source->name,
            ] : null),
            'customer' => $this->whenLoaded('customer', fn () => $this->customer ? new CustomerResource($this->customer) : null),
            'converted_customer' => $this->whenLoaded('convertedCustomer', fn () => $this->convertedCustomer ? new CustomerResource($this->convertedCustomer) : null),
            'activities' => PipelineLeadActivityResource::collection($this->whenLoaded('activities')),
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
