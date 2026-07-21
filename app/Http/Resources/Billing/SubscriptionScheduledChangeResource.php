<?php

namespace App\Http\Resources\Billing;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SubscriptionScheduledChangeResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'subscription_id' => $this->subscription_id,
            'business_id' => $this->business_id,
            'change_type' => $this->change_type,
            'from_plan_id' => $this->from_plan_id,
            'to_plan_id' => $this->to_plan_id,
            'effective_at' => $this->effective_at,
            'status' => $this->status,
            'proration_amount' => $this->proration_amount,
            'created_at' => $this->created_at,
        ];
    }
}
