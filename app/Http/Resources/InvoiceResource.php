<?php

namespace App\Http\Resources;

use App\Http\Resources\PaymentResource;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class InvoiceResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $this->resource->loadMissing(['business:id,name', 'customer']);

        $direction = $this->resolveDirection($request);
        $seller = $this->resolveSellerBusiness();

        return [
            'id' => $this->id,
            'business_id' => $this->business_id,
            'invoice_number' => $this->invoice_number,
            'customer_id' => $this->customer_id,
            'sale_id' => $this->sale_id,
            'estimate_id' => $this->estimate_id,
            'purchase_order_id' => $this->purchase_order_id,
            'buyer_business_id' => $this->buyer_business_id,
            'direction' => $direction,
            /** Counterparty for the viewer: supplier name when received, customer when issued. */
            'party_name' => $direction === 'received'
                ? ($seller['name'] ?? 'Supplier')
                : ($this->customer?->name ?? 'Walk-in'),
            'party_role' => $direction === 'received' ? 'supplier' : 'customer',
            'seller_business' => $seller,
            'purchase_order' => $this->when(
                $this->relationLoaded('purchaseOrder') && $this->purchaseOrder,
                fn () => [
                    'id' => $this->purchaseOrder->id,
                    'po_number' => $this->purchaseOrder->po_number,
                    'status' => $this->purchaseOrder->status,
                ],
            ),
            'customer' => new CustomerResource($this->whenLoaded('customer')),
            'issue_date' => $this->issue_date?->toISOString(),
            'due_date' => $this->due_date?->toISOString(),
            'status' => $this->status,
            'subtotal' => $this->subtotal,
            'tax_total' => $this->tax_total,
            'total_amount' => $this->total_amount,
            'amount_paid' => $this->amount_paid,
            'payments' => PaymentResource::collection($this->whenLoaded('payments')),
            'notes' => $this->notes,
            'email_sent_count' => (int) ($this->email_sent_count ?? 0),
            'last_emailed_at' => $this->last_emailed_at?->toISOString(),
            'created_by' => $this->created_by,
            'created_by_user' => new UserResource($this->whenLoaded('createdBy')),
            'items' => InvoiceItemResource::collection($this->whenLoaded('items')),
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }

    /**
     * @return array{id: int, name: string}|null
     */
    protected function resolveSellerBusiness(): ?array
    {
        if ($this->business) {
            return [
                'id' => (int) $this->business->id,
                'name' => (string) $this->business->name,
            ];
        }

        if ($this->business_id) {
            return [
                'id' => (int) $this->business_id,
                'name' => 'Supplier',
            ];
        }

        return null;
    }

    protected function resolveDirection(Request $request): string
    {
        $businessId = (int) ($request->user()?->business_id ?? 0);
        if ($businessId > 0 && (int) $this->buyer_business_id === $businessId && (int) $this->business_id !== $businessId) {
            return 'received';
        }

        return 'issued';
    }
}
