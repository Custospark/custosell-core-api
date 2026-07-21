<?php

namespace App\Enums\Billing;

enum ScheduledChangeType: string
{
    case UPGRADE = 'upgrade';
    case DOWNGRADE = 'downgrade';
    case CANCEL = 'cancel';
    case PLAN_CHANGE = 'plan_change';
}
