<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PipelineBoardResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'business_id' => $this->business_id,
            'name' => $this->name,
            'description' => $this->description,
            'visibility' => $this->visibility,
            'cover_color' => $this->cover_color,
            'is_default' => $this->is_default,
            'is_archived' => $this->is_archived,
            'project_id' => $this->project_id,
            'workspace' => $this->project_id ? 'estimates' : ($this->workspace ?? 'pipeline'),
            'background_type' => $this->background_type,
            'background_value' => $this->background_value,
            'sort_order' => $this->sort_order,
            'open_leads_count' => $this->when(isset($this->open_leads_count), (int) $this->open_leads_count),
            'created_by' => $this->created_by,
            'creator' => $this->whenLoaded('creator', fn () => [
                'id' => $this->creator->id,
                'name' => $this->creator->name,
            ]),
            'members' => PipelineBoardMemberResource::collection($this->whenLoaded('members')),
            'stages' => PipelineStageResource::collection($this->whenLoaded('stages')),
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
