<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class EstimateTemplateResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'business_id' => $this->business_id,
            'name' => $this->name,
            'description' => $this->description,
            'line_items_template' => $this->line_items_template,
            'terms' => $this->terms,
            'default_tax_rate' => $this->default_tax_rate,
            'is_active' => $this->is_active,
            'created_by' => $this->created_by,
            'created_by_user' => new UserResource($this->whenLoaded('createdBy')),
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
