<?php

namespace App\Services\Billing;

use App\Models\PersonalModuleSubscription;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;

class PersonalSubscriptionService
{
    /** Available personal module slugs with their display info. */
    public const AVAILABLE_MODULES = [
        'pipeline' => [
            'label' => 'Pipeline (Project Management)',
            'description' => 'Boards, tasks, leads, and insights for individuals.',
            'price_monthly_usd' => 5,
            'price_yearly_usd' => 50,
        ],
        'accounting' => [
            'label' => 'Accounting',
            'description' => 'Personal bookkeeping — chart of accounts, journals, and financial reports.',
            'price_monthly_usd' => 5,
            'price_yearly_usd' => 50,
        ],
    ];

    public function activeModules(User $user): Collection
    {
        return $user->personalModuleSubscriptions()
            ->where('status', 'active')
            ->get();
    }

    public function hasActiveModule(User $user, string $moduleSlug): bool
    {
        return $user->personalModuleSubscriptions()
            ->where('module_slug', $moduleSlug)
            ->where('status', 'active')
            ->exists();
    }

    public function subscribe(User $user, string $moduleSlug, string $billingCycle = 'monthly'): PersonalModuleSubscription
    {
        if (!isset(self::AVAILABLE_MODULES[$moduleSlug])) {
            throw new \InvalidArgumentException("Module '{$moduleSlug}' is not available for personal subscription.");
        }

        $existing = $user->personalModuleSubscriptions()
            ->where('module_slug', $moduleSlug)
            ->first();

        if ($existing) {
            if ($existing->status === 'active') {
                throw new \RuntimeException("Already subscribed to '{$moduleSlug}'.");
            }
            $existing->update([
                'status' => 'active',
                'billing_cycle' => $billingCycle,
                'price_usd' => self::AVAILABLE_MODULES[$moduleSlug]["price_{$billingCycle}_usd"],
                'cancelled_at' => null,
            ]);
            return $existing->fresh();
        }

        $price = self::AVAILABLE_MODULES[$moduleSlug]["price_{$billingCycle}_usd"];

        return PersonalModuleSubscription::create([
            'user_id' => $user->id,
            'module_slug' => $moduleSlug,
            'status' => 'active',
            'billing_cycle' => $billingCycle,
            'price_usd' => $price,
            'current_period_start' => now(),
            'current_period_end' => $billingCycle === 'monthly' ? now()->addMonth() : now()->addYear(),
        ]);
    }

    public function cancel(PersonalModuleSubscription $subscription): void
    {
        $subscription->update([
            'status' => 'cancelled',
            'cancelled_at' => now(),
        ]);
    }

    public function activeModuleSlugs(User $user): array
    {
        return $this->activeModules($user)->pluck('module_slug')->toArray();
    }

    /** Total monthly cost for all active modules. */
    public function totalMonthly(User $user): float
    {
        return (float) $this->activeModules($user)->sum('price_usd');
    }
}
