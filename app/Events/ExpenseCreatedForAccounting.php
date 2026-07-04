<?php

namespace App\Events;

use App\Models\Expense;
use Illuminate\Foundation\Events\Dispatchable;

class ExpenseCreatedForAccounting
{
    use Dispatchable;

    public function __construct(
        public Expense $expense,
    ) {}
}
