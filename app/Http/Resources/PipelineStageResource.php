<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PipelineStageResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $totalValue = 0.0;
        $currency = null;
        if ($this->relationLoaded('leads')) {
            foreach ($this->leads as $lead) {
                if ($lead->estimated_value !== null) {
                    $totalValue += (float) $lead->estimated_value;
                    $currency ??= $lead->currency;
                }
            }
        }

        return [
            'id' => $this->id,
            'board_id' => $this->board_id,
            'name' => $this->name,
            'sort_order' => $this->sort_order,
            'color' => $this->color,
            'is_won' => $this->is_won,
            'is_lost' => $this->is_lost,
            'rotting_days' => $this->rotting_days,
            'total_value' => round($totalValue, 2),
            'currency' => $currency,
            'leads' => PipelineLeadResource::collection($this->whenLoaded('leads')),
        ];
    }
}
