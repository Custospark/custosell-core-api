<?php

namespace App\Enums\Billing;

enum PaymentType: string
{
    case ONBOARDING = 'onboarding';
    case SUBSCRIPTION = 'subscription';
    case RENEWAL = 'renewal';
    case TOPUP = 'topup';
    case UPGRADE_PRORATION = 'upgrade_proration';
    case BILLING_CYCLE_CHANGE = 'billing_cycle_change';
}
