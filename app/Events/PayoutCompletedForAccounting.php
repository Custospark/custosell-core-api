<?php

namespace App\Events;

use App\Models\Payout;
use Illuminate\Foundation\Events\Dispatchable;

class PayoutCompletedForAccounting
{
    use Dispatchable;

    public function __construct(
        public Payout $payout,
    ) {}
}
