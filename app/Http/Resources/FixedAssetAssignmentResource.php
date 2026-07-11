<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class FixedAssetAssignmentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'business_id' => $this->business_id,
            'asset_id' => $this->asset_id,
            'from_employee_id' => $this->from_employee_id,
            'to_employee_id' => $this->to_employee_id,
            'action' => $this->action,
            'notes' => $this->notes,
            'performed_by' => $this->performed_by,
            'occurred_at' => $this->occurred_at?->toISOString(),
            'from_employee' => $this->whenLoaded('fromEmployee', function () {
                if (!$this->fromEmployee) {
                    return null;
                }

                return [
                    'id' => $this->fromEmployee->id,
                    'first_name' => $this->fromEmployee->first_name,
                    'last_name' => $this->fromEmployee->last_name,
                    'employee_number' => $this->fromEmployee->employee_number,
                ];
            }),
            'to_employee' => $this->whenLoaded('toEmployee', function () {
                if (!$this->toEmployee) {
                    return null;
                }

                return [
                    'id' => $this->toEmployee->id,
                    'first_name' => $this->toEmployee->first_name,
                    'last_name' => $this->toEmployee->last_name,
                    'employee_number' => $this->toEmployee->employee_number,
                ];
            }),
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
