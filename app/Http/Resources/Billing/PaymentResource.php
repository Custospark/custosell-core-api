<?php

namespace App\Http\Resources\Billing;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PaymentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'business_id' => $this->business_id,
            'subscription_id' => $this->subscription_id,
            'amount' => $this->amount,
            'currency' => $this->currency,
            'method' => $this->method,
            'payment_type' => $this->payment_type,
            'status' => $this->status,
            'transaction_reference' => $this->transaction_reference,
            'gateway_name' => $this->gateway_name,
            'gateway_transaction_id' => $this->gateway_transaction_id,
            'paid_at' => $this->paid_at,
            'approved_at' => $this->approved_at,
            'rejection_reason' => $this->rejection_reason,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
