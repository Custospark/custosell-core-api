<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PipelineChecklistItemResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'checklist_id' => $this->checklist_id,
            'title' => $this->title,
            'description' => $this->description,
            'is_done' => $this->is_done,
            'sort_order' => $this->sort_order,
        ];
    }
}
