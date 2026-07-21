<?php

namespace App\Enums\Billing;

enum PaymentType: string
{
    case ONBOARDING = 'onboarding';
    case SUBSCRIPTION = 'subscription';
    case RENEWAL = 'renewal';
    case UPGRADE_PRORATION = 'upgrade_proration';
}
