<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PurchaseOrderResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'buyer_business_id' => $this->buyer_business_id,
            'buyer_business' => new MarketplaceBusinessResource($this->whenLoaded('buyerBusiness')),
            'seller_business_id' => $this->seller_business_id,
            'seller_business' => new MarketplaceBusinessResource($this->whenLoaded('sellerBusiness')),
            'po_number' => $this->po_number,
            'status' => $this->status,
            'payment_status' => $this->payment_status,
            'subtotal' => $this->subtotal,
            'tax_total' => $this->tax_total,
            'discount_amount' => $this->discount_amount,
            'total_amount' => $this->total_amount,
            'notes' => $this->notes,
            'rejection_reason' => $this->rejection_reason,
            'invoice_id' => $this->when(
                $this->relationLoaded('invoice'),
                fn () => $this->invoice?->id,
            ),
            'invoice' => $this->when(
                $this->relationLoaded('invoice') && $this->invoice,
                fn () => [
                    'id' => $this->invoice->id,
                    'invoice_number' => $this->invoice->invoice_number,
                    'status' => $this->invoice->status,
                    'amount_paid' => $this->invoice->amount_paid,
                    'total_amount' => $this->invoice->total_amount,
                    'payments_count' => $this->invoice->relationLoaded('payments')
                        ? $this->invoice->payments->count()
                        : null,
                ],
            ),
            'items' => PurchaseOrderItemResource::collection($this->whenLoaded('items')),
            'submitted_at' => $this->submitted_at?->toISOString(),
            'accepted_at' => $this->accepted_at?->toISOString(),
            'rejected_at' => $this->rejected_at?->toISOString(),
            'fulfilled_at' => $this->fulfilled_at?->toISOString(),
            'received_at' => $this->received_at?->toISOString(),
            'cancelled_at' => $this->cancelled_at?->toISOString(),
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
