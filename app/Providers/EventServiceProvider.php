<?php

namespace App\Providers;

use App\Events\ExpenseCreatedForAccounting;
use App\Events\SaleCreatedForAccounting;
use App\Events\SaleRefundedForAccounting;
use App\Listeners\CreateJournalEntryForExpense;
use App\Listeners\CreateJournalEntryForSale;
use App\Listeners\CreateReversingEntryForRefund;
use Illuminate\Foundation\Support\Providers\EventServiceProvider as ServiceProvider;

class EventServiceProvider extends ServiceProvider
{
    protected $listen = [
        SaleCreatedForAccounting::class => [
            CreateJournalEntryForSale::class,
        ],
        SaleRefundedForAccounting::class => [
            CreateReversingEntryForRefund::class,
        ],
        ExpenseCreatedForAccounting::class => [
            CreateJournalEntryForExpense::class,
        ],
    ];

    public function boot(): void
    {
        parent::boot();
    }
}
