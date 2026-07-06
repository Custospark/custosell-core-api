<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ProjectResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'business_id' => $this->business_id,
            'project_number' => $this->project_number,
            'name' => $this->name,
            'status' => $this->status,
            'currency' => $this->currency,
            'customer_id' => $this->customer_id,
            'customer' => new CustomerResource($this->whenLoaded('customer')),
            'estimate_id' => $this->estimate_id,
            'estimate' => new EstimateResource($this->whenLoaded('estimate')),
            'pipeline_lead_id' => $this->pipeline_lead_id,
            'budget_revenue' => $this->budget_revenue,
            'budget_cost' => $this->budget_cost,
            'actual_cost' => $this->actual_cost,
            'actual_revenue' => $this->actual_revenue,
            'start_date' => $this->start_date?->toISOString(),
            'due_date' => $this->due_date?->toISOString(),
            'completed_at' => $this->completed_at?->toISOString(),
            'description' => $this->description,
            'manager_id' => $this->manager_id,
            'manager' => new UserResource($this->whenLoaded('manager')),
            'created_by' => $this->created_by,
            'created_by_user' => new UserResource($this->whenLoaded('createdBy')),
            'tasks' => ProjectTaskResource::collection($this->whenLoaded('tasks')),
            'timesheet_entries' => TimesheetEntryResource::collection($this->whenLoaded('timesheetEntries')),
            'cost_allocations' => ProjectCostAllocationResource::collection($this->whenLoaded('costAllocations')),
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
