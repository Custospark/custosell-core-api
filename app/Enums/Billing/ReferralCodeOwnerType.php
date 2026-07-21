<?php

namespace App\Enums\Billing;

enum ReferralCodeOwnerType: string
{
    case BUSINESS = 'business';
    case SALES_REP = 'sales_rep';
    case CAMPAIGN = 'campaign';
}
