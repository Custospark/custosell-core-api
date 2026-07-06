<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ProjectTaskResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'project_id' => $this->project_id,
            'name' => $this->name,
            'description' => $this->description,
            'status' => $this->status,
            'sort_order' => $this->sort_order,
            'estimated_hours' => $this->estimated_hours,
            'actual_hours' => $this->actual_hours,
            'budget_cost' => $this->budget_cost,
            'due_date' => $this->due_date?->toISOString(),
            'assigned_to' => $this->assigned_to,
            'assigned_to_user' => new UserResource($this->whenLoaded('assignedTo')),
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
