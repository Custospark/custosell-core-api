<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TimesheetEntryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'business_id' => $this->business_id,
            'project_id' => $this->project_id,
            'project_task_id' => $this->project_task_id,
            'project_task' => new ProjectTaskResource($this->whenLoaded('projectTask')),
            'user_id' => $this->user_id,
            'user' => new UserResource($this->whenLoaded('user')),
            'entry_date' => $this->entry_date?->toISOString(),
            'hours' => $this->hours,
            'hourly_rate' => $this->hourly_rate,
            'total_cost' => $this->total_cost,
            'notes' => $this->notes,
            'is_billable' => $this->is_billable,
            'status' => $this->status,
            'created_by' => $this->created_by,
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
