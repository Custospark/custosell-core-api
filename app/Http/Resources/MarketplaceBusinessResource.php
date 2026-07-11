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
            'description' => $this->description,
            'business_email' => $this->business_email,
            'business_phone' => $this->business_phone,
            'city' => $this->city,
            'state' => $this->state,
            'country' => $this->country,
        ];
    }
}
