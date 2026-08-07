<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class IncomeSourceResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'business_id' => $this->business_id,
            'user_id' => $this->user_id,
            'user' => new UserResource($this->whenLoaded('user')),
            'amount' => $this->amount,
            'source_name' => $this->source_name,
            'description' => $this->description,
            'income_date' => $this->income_date?->toISOString(),
            'is_recurring' => $this->is_recurring,
            'recurrence_interval' => $this->recurrence_interval,
            'next_due_date' => $this->next_due_date?->toISOString(),
            'attachments' => IncomeSourceAttachmentResource::collection($this->whenLoaded('attachments')),
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
