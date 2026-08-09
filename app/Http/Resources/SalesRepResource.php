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
            'discount_rate' => $this->discount_rate,
            'commission_type' => $this->commission_type,
            'is_active' => $this->is_active,
            'phone' => $this->phone,
            'region' => $this->region,
            'payment_method' => $this->payment_method,
            'mobile_money_provider' => $this->mobile_money_provider,
            'mobile_money_number' => $this->mobile_money_number,
            'mobile_money_name' => $this->mobile_money_name,
            'bank_name' => $this->bank_name,
            'bank_branch' => $this->bank_branch,
            'bank_account_name' => $this->bank_account_name,
            'bank_account_number' => $this->bank_account_number,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
