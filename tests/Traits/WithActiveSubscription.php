<?php

namespace Tests\Traits;

use App\Enums\Billing\SubscriptionStatus;
use App\Models\Plan;
use App\Models\Subscription;

trait WithActiveSubscription
{
    protected function createActiveSubscription(?int $businessId = null, ?int $planId = null): Subscription
    {
        $businessId = $businessId ?? ($this->business->id ?? throw new \RuntimeException('Business not available'));
        $planId = $planId ?? Plan::where('slug', 'essential')->first()?->id ?? Plan::first()->id;

        return Subscription::create([
            'business_id' => $businessId,
            'plan_id' => $planId,
            'status' => SubscriptionStatus::ACTIVE,
            'billing_cycle' => 'monthly',
            'starts_at' => now()->subMonth(),
            'next_billing_date' => now()->addMonth(),
        ]);
    }
}
