<?php

namespace App\Http\Resources;

use App\Services\ProjectAccessService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ProjectResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $user = $request->user();
        $canCosting = $user
            ? app(ProjectAccessService::class)->canViewProjectCosting($user)
            : true;

        $data = [
            'id' => $this->id,
            'business_id' => $this->business_id,
            'project_number' => $this->project_number,
            'name' => $this->name,
            'status' => $this->status,
            'currency' => $this->currency,
            'customer_id' => $this->customer_id,
            'customer' => new CustomerResource($this->whenLoaded('customer')),
            'estimate_id' => $this->estimate_id,
            'pipeline_lead_id' => $this->pipeline_lead_id,
            'start_date' => $this->start_date?->toISOString(),
            'due_date' => $this->due_date?->toISOString(),
            'completed_at' => $this->completed_at?->toISOString(),
            'description' => $this->description,
            'manager_id' => $this->manager_id,
            'manager' => new UserResource($this->whenLoaded('manager')),
            'created_by' => $this->created_by,
            'created_by_user' => new UserResource($this->whenLoaded('createdBy')),
            'is_personal' => (bool) ($this->is_personal ?? false),
            'board_id' => $this->when(
                $this->relationLoaded('board'),
                fn () => $this->board?->id,
            ),
            'tasks' => ProjectTaskResource::collection($this->whenLoaded('tasks')),
            'members' => ProjectMemberResource::collection($this->whenLoaded('members')),
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];

        if ($canCosting) {
            $data['estimate'] = new EstimateResource($this->whenLoaded('estimate'));
            $data['budget_revenue'] = $this->budget_revenue;
            $data['budget_cost'] = $this->budget_cost;
            $data['actual_cost'] = $this->actual_cost;
            $data['actual_revenue'] = $this->actual_revenue;
            $data['timesheet_entries'] = TimesheetEntryResource::collection($this->whenLoaded('timesheetEntries'));
            $data['cost_allocations'] = ProjectCostAllocationResource::collection($this->whenLoaded('costAllocations'));
        }

        return $data;
    }
}
