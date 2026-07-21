<?php

namespace App\Enums\Billing;

enum DiscountType: string
{
    case PERCENTAGE = 'percentage';
    case FLAT_AMOUNT = 'flat_amount';
    case FREE_MONTH = 'free_month';
}
