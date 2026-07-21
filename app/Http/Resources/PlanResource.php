<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PlanResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'slug' => $this->slug,
            'description' => $this->description,
            'price_monthly' => $this->price_monthly,
            'price_yearly' => $this->price_yearly,
            'price_monthly_usd' => $this->price_monthly_usd,
            'price_yearly_usd' => $this->price_yearly_usd,
            'onboarding_fee_ugx' => $this->onboarding_fee_ugx,
            'onboarding_fee_usd' => $this->onboarding_fee_usd,
            'trial_days' => $this->trial_days,
            'billing_cycle' => $this->billing_cycle,
            'is_popular' => $this->is_popular,
            'features' => $this->features,
            'limits' => $this->limits,
            'metadata' => $this->metadata,
            'is_active' => $this->is_active,
            'sort_order' => $this->sort_order,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
