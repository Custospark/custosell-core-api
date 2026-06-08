<?php

namespace App\Http\Resources;

use App\Services\Platform\PlatformAdminService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PlatformUserResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        /** @var PlatformAdminService $platformAdmin */
        $platformAdmin = app(PlatformAdminService::class);

        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'phone' => $this->phone,
            'is_active' => $this->is_active,
            'business_id' => $this->business_id,
            'business_name' => $this->whenLoaded('business', fn () => $this->business?->name),
            'role_name' => $this->whenLoaded('role', fn () => $this->role?->name),
            'platform_roles' => $this->relationLoaded('roles')
                ? $this->roles->pluck('name')->values()->all()
                : [],
            'is_platform_admin' => $platformAdmin->isPlatformAdmin($this->resource),
            'last_login_at' => $this->last_login_at?->toIso8601String(),
            'days_since_login' => $this->last_login_at
                ? (int) $this->last_login_at->diffInDays(now())
                : null,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
