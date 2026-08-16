<?php

namespace App\Events;

use App\Models\BillingPayment;
use Illuminate\Foundation\Events\Dispatchable;

class SubscriptionPaymentCompletedForAccounting
{
    use Dispatchable;

    public function __construct(
        public BillingPayment $payment,
    ) {}
}
