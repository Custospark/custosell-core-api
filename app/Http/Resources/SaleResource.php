<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SaleResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'business_id' => $this->business_id,
            'user_id' => $this->user_id,
            'user' => new UserResource($this->whenLoaded('user')),
            'customer_id' => $this->customer_id,
            'customer' => new CustomerResource($this->whenLoaded('customer')),
            'shift_id' => $this->shift_id,
            'shift' => new ShiftResource($this->whenLoaded('shift')),
            'business' => new BusinessResource($this->whenLoaded('business')),
            'receipt_number' => $this->receipt_number,
            'subtotal' => $this->subtotal,
            'tax_total' => $this->tax_total,
            'discount_amount' => $this->discount_amount,
            'total_amount' => $this->total_amount,
            'amount_tendered' => $this->amount_tendered,
            'change_given' => $this->change_given,
            'payment_method' => $this->payment_method,
            'payment_status' => $this->payment_status,
            'notes' => $this->notes,
            'sale_date' => $this->sale_date?->toISOString(),
            'sale_items' => SaleItemResource::collection($this->whenLoaded('saleItems')),
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
