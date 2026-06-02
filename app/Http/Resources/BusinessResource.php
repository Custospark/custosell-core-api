<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class BusinessResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'owner_id' => $this->owner_id,
            'name' => $this->name,
            'slug' => $this->slug,
            'email' => $this->email,
            'phone' => $this->phone,
            'address' => $this->address,
            'currency' => $this->currency,
            'receipt_footer' => $this->receipt_footer,
            'logo_path' => $this->logo_path,
            'status' => $this->status,
            'trial_ends_at' => $this->trial_ends_at,
            'subscription' => $this->whenLoaded('subscription'),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
