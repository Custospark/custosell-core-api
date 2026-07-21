<?php

namespace App\Enums\Billing;

enum CommissionType: string
{
    case PERCENTAGE = 'percentage';
    case FLAT = 'flat';
}
