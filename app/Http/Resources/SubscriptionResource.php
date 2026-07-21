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
            'billing_cycle' => $this->billing_cycle,
            'starts_at' => $this->starts_at,
            'trial_ends_at' => $this->trial_ends_at,
            'ends_at' => $this->ends_at,
            'next_billing_date' => $this->next_billing_date,
            'grace_period_ends_at' => $this->grace_period_ends_at,
            'cancelled_at' => $this->cancelled_at,
            'suspended_at' => $this->suspended_at,
            'approved_at' => $this->approved_at,
            'onboarding_fee_paid' => $this->onboarding_fee_paid,
            'payments' => \App\Http\Resources\Billing\PaymentResource::collection($this->whenLoaded('payments')),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
