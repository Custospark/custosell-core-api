<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class EstimateLineItemResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'estimate_id' => $this->estimate_id,
            'product_id' => $this->product_id,
            'product' => new ProductResource($this->whenLoaded('product')),
            'sort_order' => $this->sort_order,
            'type' => $this->type,
            'description' => $this->description,
            'quantity' => $this->quantity,
            'unit_cost' => $this->unit_cost,
            'unit_price' => $this->unit_price,
            'markup_type' => $this->markup_type,
            'markup_value' => $this->markup_value,
            'total_cost' => $this->total_cost,
            'total_price' => $this->total_price,
            'is_billable' => $this->is_billable,
        ];
    }
}
