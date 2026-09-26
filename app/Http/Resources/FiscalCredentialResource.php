<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class FiscalCredentialResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'business_id' => $this->business_id,
            'location_id' => $this->location_id,
            'location_name' => $this->whenLoaded('location', fn () => $this->location?->name),
            'country' => $this->country,
            'tin' => $this->tin,
            'device_no' => $this->device_no,
            'branch_id' => $this->branch_id,
            'api_username' => $this->api_username,
            'has_api_password' => true,
            'private_key_path' => $this->private_key_path,
            'public_key_path' => $this->public_key_path,
            'environment' => $this->environment,
            'is_active' => (bool) $this->is_active,
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
