<?php

namespace App\Http\Resources;

use App\Services\ProjectAccessService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class EstimateLineItemResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $user = $request->user();
        $canCosting = $user
            ? app(ProjectAccessService::class)->canViewProjectCosting($user)
            : true;

        $data = [
            'id' => $this->id,
            'estimate_id' => $this->estimate_id,
            'product_id' => $this->product_id,
            'product' => new ProductResource($this->whenLoaded('product')),
            'sort_order' => $this->sort_order,
            'type' => $this->type,
            'description' => $this->description,
            'quantity' => $this->quantity,
            'unit_price' => $this->unit_price,
            'is_billable' => $this->is_billable,
        ];

        if ($canCosting) {
            $data['unit_cost'] = $this->unit_cost;
            $data['markup_type'] = $this->markup_type;
            $data['markup_value'] = $this->markup_value;
            $data['total_cost'] = $this->total_cost;
            $data['total_price'] = $this->total_price;
        } else {
            $data['total_price'] = $this->total_price;
        }

        return $data;
    }
}
