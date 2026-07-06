<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class EstimateVersionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'estimate_id' => $this->estimate_id,
            'version' => $this->version,
            'snapshot' => $this->snapshot,
            'change_summary' => $this->change_summary,
            'created_by' => $this->created_by,
            'created_by_user' => new UserResource($this->whenLoaded('createdBy')),
            'created_at' => $this->created_at?->toISOString(),
        ];
    }
}
