<?php

namespace App\Http\Resources;

use App\Http\Resources\PlanResource;
use App\Models\Plan;
use App\Services\Billing\SubscriptionPaymentActionResolver;
use App\Services\OnboardingService;
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
            'location_id' => $this->location_id,
            'role_id' => $this->role_id,
            'location' => $this->whenLoaded('location', fn () => $this->location ? [
                'id' => $this->location->id,
                'name' => $this->location->name,
                'code' => $this->location->code,
                'is_default' => $this->location->is_default,
            ] : null),
            'locations' => $this->whenLoaded('locations', fn () => $this->locations->map(fn ($loc) => [
                'id' => $loc->id,
                'name' => $loc->name,
                'code' => $loc->code,
                'is_default' => $loc->is_default,
            ])),
            'is_active' => (bool) ($this->is_active ?? true),
            'email_verified_at' => $this->email_verified_at?->toISOString(),
            'two_factor_enabled' => (bool) $this->two_factor_enabled,
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
                    'default_vat_rate' => $this->business?->default_vat_rate,
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
                    'primary_intent' => $this->business?->primary_intent,
                    'secondary_intent' => $this->business?->secondary_intent,
                    'created_at' => $this->business?->created_at,
                        'subscription' => $this->when(
                            $this->business?->relationLoaded('subscription') && $this->business->subscription,
                            fn () => [
                                'id' => $this->business->subscription->id,
                                'plan_id' => $this->business->subscription->plan_id,
                                'plan_name' => $this->business->subscription->plan?->name,
                                'plan_slug' => $this->business->subscription->plan?->slug,
                                'plan_features' => $this->business->subscription->plan?->features,
                                'plan_limits' => $this->business->subscription->plan?->limits,
                                'price_monthly_usd' => $this->business->subscription->price_monthly_usd,
                                'price_yearly_usd' => $this->business->subscription->price_yearly_usd,
                                'onboarding_fee_usd' => $this->business->subscription->onboarding_fee_usd,
                                'onboarding_fee_paid' => (bool) ($this->business->subscription->onboarding_fee_paid ?? false),
                                'payment_action' => app(SubscriptionPaymentActionResolver::class)->resolve($this->business->subscription),
                                'status' => $this->business->subscription->status?->value,
                                'billing_cycle' => $this->business->subscription->billing_cycle,
                                'starts_at' => $this->business->subscription->starts_at,
                                'trial_ends_at' => $this->business->subscription->trial_ends_at,
                                'ends_at' => $this->business->subscription->ends_at,
                                'next_billing_date' => $this->business->subscription->next_billing_date,
                                'grace_period_ends_at' => $this->business->subscription->grace_period_ends_at,
                                'cancelled_at' => $this->business->subscription->cancelled_at,
                                'suspended_at' => $this->business->subscription->suspended_at,
                                'approved_at' => $this->business->subscription->approved_at,
                                'referral' => $this->business->subscription->relationLoaded('referral') && $this->business->subscription->referral
                                    ? [
                                        'code' => $this->business->subscription->referral->referralCode?->code,
                                        'discount_type' => $this->business->subscription->referral->referralCode?->discount_type,
                                        'discount_value' => $this->business->subscription->referral->referralCode?->discount_value,
                                        'discount_duration_months' => $this->business->subscription->referral->referralCode?->discount_duration_months,
                                        'discount_applied' => $this->business->subscription->referral->discount_applied,
                                        'status' => $this->business->subscription->referral->status,
                                    ]
                                    : null,
                            ]
                        ),
                ];
            }),
            'modules' => $this->resolveModules(),
            'account_type' => $this->account_type ?? 'business',
            'onboarding' => app(OnboardingService::class)->payloadFor($this->resource),
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
            'active_plans' => $this->account_type === 'storefront_buyer'
                ? PlanResource::collection([])
                : PlanResource::collection(
                    Plan::active()
                        ->where('type', $this->account_type === 'personal' ? 'personal' : 'business')
                        ->orderBy('sort_order')
                        ->get(),
                ),
        ];
    }

    private function resolveModules(): array
    {
        if ($this->account_type === 'personal') {
            if (! $this->business_id || ! $this->business) {
                return [];
            }

            $planFeatures = $this->business?->relationLoaded('subscription')
                && $this->business->subscription
                && $this->business->subscription->relationLoaded('plan')
                && $this->business->subscription->plan
                && $this->business->subscription->hasAccess()
                ? ($this->business->subscription->plan->features ?? [])
                : [];
            $modules = array_keys(array_filter($planFeatures));
            return array_values(array_unique([
                'account', 'guide', 'discover',
                ...$modules,
            ]));
        }

        return $this->modules ?? [];
    }
}
