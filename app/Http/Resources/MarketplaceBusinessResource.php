<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class MarketplaceBusinessResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'supply_headline' => $this->supply_headline,
            'city' => $this->city,
            'state' => $this->state,
            'country' => $this->country,
        ];
    }
}
