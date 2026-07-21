<?php

namespace App\Enums\Billing;

enum PaymentMethod: string
{
    case GATEWAY = 'gateway';
    case MANUAL = 'manual';
}
