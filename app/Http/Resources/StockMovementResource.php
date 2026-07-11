<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class StockMovementResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'business_id' => $this->business_id,
            'product_id' => $this->product_id,
            'product' => new ProductResource($this->whenLoaded('product')),
            'sale_item_id' => $this->sale_item_id,
            'type' => $this->type,
            'quantity_change' => $this->quantity_change,
            'stock_before' => $this->stock_before,
            'stock_after' => $this->stock_after,
            'reference' => $this->reference,
            'notes' => $this->notes,
            'created_by' => $this->created_by,
            'created_by_user' => $this->when(
                $this->relationLoaded('createdBy') && $this->createdBy !== null,
                fn () => new UserResource($this->createdBy),
            ),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
