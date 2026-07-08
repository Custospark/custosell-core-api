<?php

namespace App\Http\Resources;

use App\Services\Platform\PlatformAdminService;
use App\Services\ProjectAccessService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class UserResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'phone' => $this->phone,
            'avatar' => $this->avatar,
            'business_id' => $this->business_id,
            'role_id' => $this->role_id,
            'is_active' => (bool) ($this->is_active ?? true),
            'is_business_owner' => $this->is_business_owner ?? $this->business?->owner_id === $this->id,
            'business_name' => $this->whenLoaded('business', fn () => $this->business?->name),
            'business' => $this->whenLoaded('business', function () {
                // Auth payload is used for offline-first navbar rendering.
                // Include `logo_path` so staff users see the same navbar business branding as the owner.
                return [
                    'id' => $this->business?->id,
                    'owner_id' => $this->business?->owner_id,
                    'name' => $this->business?->name,
                    'slug' => $this->business?->slug,
                    'email' => $this->business?->email,
                    'phone' => $this->business?->phone,
                    'website' => $this->business?->website,
                    'address' => $this->business?->address,
                    'city' => $this->business?->city,
                    'state' => $this->business?->state,
                    'postal_code' => $this->business?->postal_code,
                    'country' => $this->business?->country,
                    'tax_id' => $this->business?->tax_id,
                    'tax_regime' => $this->business?->tax_regime ?? 'none',
                    'jurisdiction' => $this->business?->jurisdiction ?? 'UG',
                    'prices_include_tax' => (bool) ($this->business?->prices_include_tax ?? true),
                    'description' => $this->business?->description,
                    'business_email' => $this->business?->business_email,
                    'business_phone' => $this->business?->business_phone,
                    'timezone' => $this->business?->timezone,
                    'business_type' => $this->business?->business_type,
                    'currency' => $this->business?->currency,
                    'receipt_footer' => $this->business?->receipt_footer,
                    'payment_bank_name' => $this->business?->payment_bank_name,
                    'payment_bank_account_name' => $this->business?->payment_bank_account_name,
                    'payment_bank_account_number' => $this->business?->payment_bank_account_number,
                    'payment_bank_branch' => $this->business?->payment_bank_branch,
                    'payment_mobile_money_provider' => $this->business?->payment_mobile_money_provider,
                    'payment_mobile_money_account_name' => $this->business?->payment_mobile_money_account_name,
                    'payment_mobile_money_number' => $this->business?->payment_mobile_money_number,
                    'payment_instructions' => $this->business?->payment_instructions,
                    'logo_path' => $this->business?->logo_path,
                    'status' => $this->business?->status,
                    'trial_ends_at' => $this->business?->trial_ends_at,
                    'created_at' => $this->business?->created_at,
                ];
            }),
            'modules' => $this->modules ?? [],
            'is_platform_admin' => app(PlatformAdminService::class)->isPlatformAdmin($this->resource),
            'project_member_ids' => $this->when(
                $request->user()?->id === $this->id,
                fn () => app(ProjectAccessService::class)->memberProjectIds($this->resource),
            ),
            'role' => $this->whenLoaded('role', fn () => $this->role ? [
                'id' => $this->role->id,
                'name' => $this->role->name,
                'slug' => $this->role->slug,
            ] : null),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
