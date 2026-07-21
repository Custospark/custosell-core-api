<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ReferralCodeResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'owner_type' => $this->owner_type,
            'owner_business_id' => $this->owner_business_id,
            'owner_user_id' => $this->owner_user_id,
            'owner_business' => $this->whenLoaded('ownerBusiness'),
            'owner_user' => $this->whenLoaded('ownerUser'),
            'code' => $this->code,
            'discount_type' => $this->discount_type,
            'discount_value' => $this->discount_value,
            'discount_duration_months' => $this->discount_duration_months,
            'reward_type' => $this->reward_type,
            'reward_value' => $this->reward_value,
            'max_uses' => $this->max_uses,
            'used_count' => $this->used_count,
            'is_active' => $this->is_active,
            'expires_at' => $this->expires_at,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
