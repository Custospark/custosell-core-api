<?php

namespace App\Services\Billing;

use App\Enums\Billing\SubscriptionStatus;
use App\Models\Subscription;

class SubscriptionPaymentActionResolver
{
    public function resolve(?Subscription $subscription): array
    {
        if (!$subscription) {
            return [
                'required' => true,
                'intent' => 'subscribe',
                'label' => 'Subscribe',
                'message' => 'No subscription found. Choose a plan to get started.',
            ];
        }

        if (! $subscription->onboarding_fee_paid && (float) ($subscription->onboarding_fee_usd ?? 0) > 0) {
            return [
                'required' => true,
                'intent' => 'pay_onboarding',
                'label' => 'Pay Setup Fee',
                'message' => 'Complete the one-time setup fee to activate your subscription.',
            ];
        }

        return match ($subscription->status) {
            SubscriptionStatus::TRIAL => [
                'required' => false,
                'intent' => null,
                'label' => null,
                'message' => null,
            ],
            SubscriptionStatus::ACTIVE => [
                'required' => false,
                'intent' => null,
                'label' => null,
                'message' => null,
            ],
            SubscriptionStatus::PAST_DUE => [
                'required' => true,
                'intent' => 'renew',
                'label' => 'Pay Outstanding',
                'message' => 'Your subscription payment is overdue. Complete payment to restore access.',
            ],
            SubscriptionStatus::SUSPENDED => [
                'required' => true,
                'intent' => 'reactivate',
                'label' => 'Reactivate',
                'message' => 'Your subscription has been suspended. Make a payment to reactivate.',
            ],
            SubscriptionStatus::EXPIRED => [
                'required' => true,
                'intent' => 'resubscribe',
                'label' => 'Re-subscribe',
                'message' => 'Your subscription has expired. Re-subscribe to continue using Custosell.',
            ],
            SubscriptionStatus::CANCELLED => [
                'required' => true,
                'intent' => 'subscribe',
                'label' => 'Subscribe',
                'message' => 'Your subscription has been cancelled. Subscribe to a new plan to continue.',
            ],
        };
    }
}
