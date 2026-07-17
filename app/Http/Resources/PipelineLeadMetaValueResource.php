<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PipelineLeadMetaValueResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'lead_id' => $this->lead_id,
            'meta_field_id' => $this->meta_field_id,
            'value' => $this->value,
            'meta_field' => $this->whenLoaded('metaField', fn () => new PipelineBoardMetaFieldResource($this->metaField)),
        ];
    }
}
