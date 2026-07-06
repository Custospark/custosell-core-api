<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ProjectCostAllocationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'business_id' => $this->business_id,
            'project_id' => $this->project_id,
            'allocation_type' => $this->allocation_type,
            'description' => $this->description,
            'amount' => $this->amount,
            'basis' => $this->basis,
            'basis_value' => $this->basis_value,
            'allocation_date' => $this->allocation_date?->toISOString(),
            'expense_id' => $this->expense_id,
            'created_by' => $this->created_by,
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
