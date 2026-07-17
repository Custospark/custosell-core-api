<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PipelineBoardMetaFieldResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'board_id' => $this->board_id,
            'name' => $this->name,
            'type' => $this->type,
            'options' => $this->options,
            'sort_order' => $this->sort_order,
            'required' => $this->required,
            'created_at' => $this->created_at,
        ];
    }
}
