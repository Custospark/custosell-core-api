<?php

namespace App\Services\Concerns;

use App\Models\User;

trait ResolvesPersonalPlanModules
{
    /**
     * Modules a personal account is granted from its live subscription plan.
     * Mirrors UserResource::resolveModules and the frontend's getAccessibleModules —
     * the mutable `user.modules` array is never the source of truth for personal
     * accounts. Requires an active/trial/past-due-in-grace subscription.
     *
     * @return list<string>
     */
    public function personalPlanModules(User $user): array
    {
        if (! $user->business_id || ! $user->business) {
            return [];
        }

        $subscription = $user->business->subscription;
        if (! $subscription || ! $subscription->hasAccess()) {
            return [];
        }

        $features = is_array($subscription->plan?->features) ? $subscription->plan->features : [];

        return array_values(array_unique(array_intersect(
            array_keys(array_filter($features, fn ($enabled) => $enabled === true)),
            static::BUSINESS_MODULES,
        )));
    }
}