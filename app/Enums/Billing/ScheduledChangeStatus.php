<?php

namespace App\Enums\Billing;

enum ScheduledChangeStatus: string
{
    case PENDING = 'pending';
    case APPLIED = 'applied';
    case CANCELLED = 'cancelled';
}
