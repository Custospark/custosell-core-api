<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PurchaseOrderItemResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'purchase_order_id' => $this->purchase_order_id,
            'product_id' => $this->product_id,
            'product_name' => $this->product_name,
            'product_sku' => $this->product_sku,
            'unit_price' => $this->unit_price,
            'quantity' => $this->quantity,
            'quantity_fulfilled' => $this->quantity_fulfilled,
            'subtotal' => $this->subtotal,
            'received_product_id' => $this->received_product_id,
            'received_product' => new ProductResource($this->whenLoaded('receivedProduct')),
        ];
    }
}
