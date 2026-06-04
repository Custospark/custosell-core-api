<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ShiftResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'business_id' => $this->business_id,
            'user_id' => $this->user_id,
            'user' => new UserResource($this->whenLoaded('user')),
            'clock_in' => $this->clock_in?->toISOString(),
            'clock_out' => $this->clock_out?->toISOString(),
            'total_sales' => $this->total_sales,
            'total_cash' => $this->total_cash,
            'total_mobile_money' => $this->total_mobile_money,
            'total_card' => $this->total_card,
            'status' => $this->status,
            'notes' => $this->notes,
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
