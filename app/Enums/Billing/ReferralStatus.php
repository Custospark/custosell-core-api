<?php

namespace App\Enums\Billing;

enum ReferralStatus: string
{
    case PENDING = 'pending';
    case ACTIVE = 'active';
    case REWARDED = 'rewarded';
}
