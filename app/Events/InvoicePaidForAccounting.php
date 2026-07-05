<?php

namespace App\Events;

use App\Models\Invoice;
use Illuminate\Foundation\Events\Dispatchable;

class InvoicePaidForAccounting
{
    use Dispatchable;

    public function __construct(
        public Invoice $invoice,
        public float $amount,
        public string $paymentMethod,
    ) {}
}
