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
            'address' => $this->address,
            'city' => $this->city,
            'state' => $this->state,
            'country' => $this->country,
            'is_open_for_supply' => (bool) $this->is_open_for_supply,
            'is_saved' => (bool) ($this->is_saved ?? false),
            'listed_products_count' => (int) ($this->listed_products_count ?? 0),
        ];
    }
}
