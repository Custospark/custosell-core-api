<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SubscriptionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'business_id' => $this->business_id,
            'business' => new BusinessResource($this->whenLoaded('business')),
            'plan_id' => $this->plan_id,
            'plan' => new PlanResource($this->whenLoaded('plan')),
            'status' => $this->status,
            'starts_at' => $this->starts_at,
            'trial_ends_at' => $this->trial_ends_at,
            'ends_at' => $this->ends_at,
            'cancelled_at' => $this->cancelled_at,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
