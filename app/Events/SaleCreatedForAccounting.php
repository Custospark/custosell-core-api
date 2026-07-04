<?php

namespace App\Events;

use App\Models\Sale;
use Illuminate\Foundation\Events\Dispatchable;

class SaleCreatedForAccounting
{
    use Dispatchable;

    public function __construct(
        public Sale $sale,
    ) {}
}
