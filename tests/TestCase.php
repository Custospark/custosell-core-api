<?php

namespace Tests;

use App\Enums\Billing\SubscriptionStatus;
use App\Models\Plan;
use App\Models\Subscription;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function ensureSubscription(?int $businessId = null, ?int $planId = null): Subscription
    {
        $businessId = $businessId ?? ($this->business->id ?? null);

        if (! $businessId) {
            throw new \RuntimeException('Cannot create subscription: no business available. Call ensureSubscription(businessId) manually.');
        }

        $existing = Subscription::where('business_id', $businessId)->first();
        if ($existing) {
            return $existing;
        }

        $planId = $planId ?? Plan::where('slug', 'essential')->first()?->id
            ?? Plan::first()?->id;

        if (! $planId) {
            $plan = Plan::create([
                'name' => 'Essential',
                'slug' => 'essential',
                'description' => 'Auto-created for tests',
                'price_monthly' => 75000,
                'features' => ['sales' => true],
                'limits' => ['staff_users' => 5],
                'is_active' => true,
                'sort_order' => 1,
            ]);
            $planId = $plan->id;
        }

        return Subscription::create([
            'business_id' => $businessId,
            'plan_id' => $planId,
            'status' => SubscriptionStatus::ACTIVE,
            'billing_cycle' => 'monthly',
            'starts_at' => now()->subMonth(),
            'next_billing_date' => now()->addMonth(),
        ]);
    }

    protected function setUpSubscription(): void
    {
        if (isset($this->business) && $this->business->id) {
            $this->ensureSubscription($this->business->id);
        }
    }
}
