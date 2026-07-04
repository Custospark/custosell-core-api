<?php

namespace App\Listeners;

use App\Events\SaleCreatedForAccounting;
use App\Services\AutomationService;

class CreateJournalEntryForSale
{
    public function __construct(
        protected AutomationService $automationService,
    ) {}

    public function handle(SaleCreatedForAccounting $event): void
    {
        $this->automationService->handleSaleCreated($event->sale);
    }
}
