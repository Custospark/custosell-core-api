<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PipelineLabelResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'business_id' => $this->business_id,
            'board_id' => $this->board_id,
            'name' => $this->name,
            'color' => $this->color,
            'sort_order' => $this->sort_order,
        ];
    }
}
