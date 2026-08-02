<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class StaffTransferResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'business_id' => $this->business_id,
            'user_id' => $this->user_id,
            'user' => $this->whenLoaded('user', fn () => $this->user ? [
                'id' => $this->user->id,
                'name' => $this->user->name,
                'email' => $this->user->email,
            ] : null),
            'from_location_id' => $this->from_location_id,
            'from_location' => $this->whenLoaded('fromLocation', fn () => $this->fromLocation ? [
                'id' => $this->fromLocation->id,
                'name' => $this->fromLocation->name,
            ] : null),
            'to_location_id' => $this->to_location_id,
            'to_location' => $this->whenLoaded('toLocation', fn () => $this->toLocation ? [
                'id' => $this->toLocation->id,
                'name' => $this->toLocation->name,
            ] : null),
            'transferred_by' => $this->transferred_by,
            'transferred_by_user' => $this->whenLoaded('transferredBy', fn () => $this->transferredBy ? [
                'id' => $this->transferredBy->id,
                'name' => $this->transferredBy->name,
            ] : null),
            'transfer_type' => $this->transfer_type,
            'status' => $this->status,
            'approval_required' => (bool) $this->approval_required,
            'approved_by' => $this->approved_by,
            'approved_by_user' => $this->whenLoaded('approvedBy', fn () => $this->approvedBy ? [
                'id' => $this->approvedBy->id,
                'name' => $this->approvedBy->name,
            ] : null),
            'approved_at' => $this->approved_at,
            'effective_at' => $this->effective_at,
            'end_at' => $this->end_at,
            'reason' => $this->reason,
            'notes' => $this->notes,
            'old_role_id' => $this->old_role_id,
            'new_role_id' => $this->new_role_id,
            'old_shift_id' => $this->old_shift_id,
            'new_shift_id' => $this->new_shift_id,
            'old_salary' => $this->old_salary,
            'new_salary' => $this->new_salary,
            'old_employment_type' => $this->old_employment_type,
            'new_employment_type' => $this->new_employment_type,
            'meta' => $this->meta,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
