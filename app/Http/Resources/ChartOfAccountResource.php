<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ChartOfAccountResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'business_id' => $this->business_id,
            'code' => $this->code,
            'name' => $this->name,
            'parent_id' => $this->parent_id,
            'parent' => new ChartOfAccountResource($this->whenLoaded('parent')),
            'account_type' => new AccountTypeResource($this->whenLoaded('accountType')),
            'type_id' => $this->type_id,
            'normal_balance' => $this->normal_balance,
            'is_active' => (bool) $this->is_active,
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
