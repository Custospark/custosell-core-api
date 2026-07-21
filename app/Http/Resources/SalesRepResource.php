<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SalesRepResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'user_id' => $this->user_id,
            'referral_code_id' => $this->referral_code_id,
            'user' => $this->whenLoaded('user'),
            'referral_code' => $this->whenLoaded('referralCode'),
            'commission_rate' => $this->commission_rate,
            'commission_type' => $this->commission_type,
            'is_active' => $this->is_active,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
