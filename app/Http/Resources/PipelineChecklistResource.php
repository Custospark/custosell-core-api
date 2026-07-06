<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PipelineChecklistResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'lead_id' => $this->lead_id,
            'title' => $this->title,
            'sort_order' => $this->sort_order,
            'items' => PipelineChecklistItemResource::collection($this->whenLoaded('items')),
        ];
    }
}
