<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class OrderResource extends JsonResource
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
            'customer_name' => $this->customer_name,
            'customer_phone' => $this->customer_phone,
            'shift_id' => $this->shift_id,
            'order_number' => $this->order_number,
            'status' => $this->status,
            'source' => $this->source ?? 'pos',
            'subtotal' => $this->subtotal,
            'tax_total' => $this->tax_total,
            'discount_amount' => $this->discount_amount,
            'total_amount' => $this->total_amount,
            'notes' => $this->notes,
            'sale_id' => $this->sale_id,
            'sale' => new SaleResource($this->whenLoaded('sale')),
            'item_count' => $this->whenLoaded('items', fn () => $this->items->sum('quantity'), null),
            'items' => OrderItemResource::collection($this->whenLoaded('items')),
            'held_at' => $this->held_at?->toISOString(),
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
