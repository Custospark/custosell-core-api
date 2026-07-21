<?php

namespace App\Enums\Billing;

enum RewardType: string
{
    case PERCENTAGE = 'percentage';
    case FLAT_AMOUNT = 'flat_amount';
    case FREE_MONTH = 'free_month';
}
