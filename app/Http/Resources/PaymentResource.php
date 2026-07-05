<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PaymentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'business_id' => $this->business_id,
            'payable_type' => $this->payable_type,
            'payable_id' => $this->payable_id,
            'receipt_number' => $this->receipt_number,
            'amount' => (float) $this->amount,
            'amount_tendered' => $this->amount_tendered !== null ? (float) $this->amount_tendered : (float) $this->amount,
            'change_given' => $this->change_given !== null ? (float) $this->change_given : null,
            'payment_method' => $this->payment_method,
            'balance_after' => (float) $this->balance_after,
            'paid_at' => $this->paid_at?->toISOString(),
            'notes' => $this->notes,
            'attachment_path' => $this->attachment_path,
            'attachment_url' => $this->attachment_path
                ? url('storage/' . ltrim($this->attachment_path, '/'))
                : null,
            'recorded_by' => $this->recorded_by,
            'recorded_by_user' => new UserResource($this->whenLoaded('recordedBy')),
            'created_at' => $this->created_at?->toISOString(),
        ];
    }
}
