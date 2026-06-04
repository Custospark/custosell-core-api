<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class UserResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'business_id' => $this->business_id,
            'role_id' => $this->role_id,
            'name' => $this->name,
            'email' => $this->email,
            'phone' => $this->phone,
            'is_active' => $this->is_active,
            'avatar' => $this->avatar
                ? (str_starts_with($this->avatar, 'http') ? $this->avatar : url($this->avatar))
                : null,
            'business_name' => $this->whenLoaded('business', fn() => $this->business->name, null),
            'business' => new BusinessResource($this->whenLoaded('business')),
            'role' => $this->whenLoaded('role'),
            'shift_clock_in' => $this->activeShift?->clock_in?->toISOString(),
            'shift_id' => $this->activeShift?->id,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
