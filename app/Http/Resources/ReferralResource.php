<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ReferralResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'referral_code_id' => $this->referral_code_id,
            'subscription_id' => $this->subscription_id,
            'referred_business_id' => $this->referred_business_id,
            'referral_code' => $this->whenLoaded('referralCode'),
            'subscription' => $this->whenLoaded('subscription'),
            'referred_business' => $this->whenLoaded('referredBusiness'),
            'status' => $this->status,
            'discount_applied' => $this->discount_applied,
            'reward_amount' => $this->reward_amount,
            'reward_paid' => $this->reward_paid,
            'converted_at' => $this->converted_at,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
