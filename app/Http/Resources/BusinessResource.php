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
            'website' => $this->website,
            'address' => $this->address,
            'city' => $this->city,
            'state' => $this->state,
            'postal_code' => $this->postal_code,
            'country' => $this->country,
            'tax_id' => $this->tax_id,
            'tax_regime' => $this->tax_regime ?? 'none',
            'jurisdiction' => $this->jurisdiction ?? 'UG',
            'default_vat_rate' => $this->default_vat_rate,
            'prices_include_tax' => (bool) ($this->prices_include_tax ?? true),
            'description' => $this->description,
            'business_email' => $this->business_email,
            'business_phone' => $this->business_phone,
            'timezone' => $this->timezone,
            'business_type' => $this->business_type,
            'currency' => $this->currency,
            'receipt_footer' => $this->receipt_footer,
            'payment_bank_name' => $this->payment_bank_name,
            'payment_bank_account_name' => $this->payment_bank_account_name,
            'payment_bank_account_number' => $this->payment_bank_account_number,
            'payment_bank_branch' => $this->payment_bank_branch,
            'payment_mobile_money_provider' => $this->payment_mobile_money_provider,
            'payment_mobile_money_account_name' => $this->payment_mobile_money_account_name,
            'payment_mobile_money_number' => $this->payment_mobile_money_number,
            'payment_instructions' => $this->payment_instructions,
            'logo_path' => $this->logo_path,
            'status' => $this->status,
            'trial_ends_at' => $this->trial_ends_at,
            'subscription' => $this->whenLoaded('subscription'),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
