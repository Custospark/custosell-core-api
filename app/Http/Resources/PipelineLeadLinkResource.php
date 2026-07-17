<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PipelineLeadLinkResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'lead_id' => $this->lead_id,
            'linked_lead_id' => $this->linked_lead_id,
            'linked_board_id' => $this->linked_board_id,
            'label' => $this->label,
            'created_by' => $this->created_by,
            'creator' => $this->whenLoaded('creator', fn () => [
                'id' => $this->creator->id,
                'name' => $this->creator->name,
            ]),
            'linked_lead' => $this->whenLoaded('linkedLead', fn () => [
                'id' => $this->linkedLead->id,
                'title' => $this->linkedLead->title,
                'card_type' => $this->linkedLead->card_type,
                'board_id' => $this->linkedLead->board_id,
                'stage_id' => $this->linkedLead->stage_id,
                'board' => $this->linkedLead->board ? [
                    'id' => $this->linkedLead->board->id,
                    'name' => $this->linkedLead->board->name,
                ] : null,
                'stage' => $this->linkedLead->stage ? [
                    'id' => $this->linkedLead->stage->id,
                    'name' => $this->linkedLead->stage->name,
                    'color' => $this->linkedLead->stage->color,
                ] : null,
            ]),
            'linked_board' => $this->whenLoaded('linkedBoard', fn () => [
                'id' => $this->linkedBoard->id,
                'name' => $this->linkedBoard->name,
                'workspace' => $this->linkedBoard->workspace,
            ]),
            'created_at' => $this->created_at,
        ];
    }
}
