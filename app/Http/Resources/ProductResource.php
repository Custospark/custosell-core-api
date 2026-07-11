<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ProductResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'business_id' => $this->business_id,
            'category_id' => $this->category_id,
            'category' => new CategoryResource($this->whenLoaded('category')),
            'name' => $this->name,
            'type' => $this->type ?? 'product',
            'unit' => $this->unit,
            'description' => $this->description,
            'sku' => $this->sku,
            'barcode' => $this->barcode,
            'unit_price' => $this->unit_price,
            'wholesale_price' => $this->wholesale_price,
            'cost_price' => $this->cost_price,
            'stock_quantity' => $this->stock_quantity,
            'low_stock_threshold' => $this->low_stock_threshold,
            'tax_percentage' => $this->tax_percentage,
            'tax_class' => $this->tax_class ?? 'standard',
            'is_active' => $this->is_active,
            'is_recurring' => (bool) $this->is_recurring,
            'billing_interval' => $this->billing_interval,
            'listed_for_supply' => (bool) $this->listed_for_supply,
            'supply_price' => $this->supply_price,
            'supply_min_qty' => $this->supply_min_qty,
            'listed_at' => $this->listed_at?->toISOString(),
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
