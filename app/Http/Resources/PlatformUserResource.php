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
            'status' => $this->status ?? ($this->is_active ? 'active' : 'deactivated'),
            'status_changed_at' => $this->status_changed_at?->toIso8601String(),
            'account_type' => $this->account_type ?? 'business',
            'business_id' => $this->business_id,
            'business_name' => $this->whenLoaded('business', fn () => $this->business?->name),
            'subscription' => $this->whenLoaded('business', fn () => $this->business?->relationLoaded('subscription')
                ? $this->buildSubscription()
                : null),
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

    /** @return array<string, mixed>|null */
    private function buildSubscription(): ?array
    {
        $subscription = $this->business->subscription;
        if (! $subscription) {
            return null;
        }

        return [
            'id' => $subscription->id,
            'plan_id' => $subscription->plan_id,
            'plan_name' => $subscription->plan?->name,
            'plan_slug' => $subscription->plan?->slug,
            'status' => $subscription->status?->value ?? $subscription->status,
            'billing_cycle' => $subscription->billing_cycle,
            'onboarding_fee_paid' => (bool) ($subscription->onboarding_fee_paid ?? false),
            'next_billing_date' => $subscription->next_billing_date?->toIso8601String(),
        ];
    }
}
