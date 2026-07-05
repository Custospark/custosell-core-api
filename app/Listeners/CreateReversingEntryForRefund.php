<?php

namespace App\Listeners;

use App\Events\SaleRefundedForAccounting;
use App\Services\AutomationService;

class CreateReversingEntryForRefund
{
    public function __construct(
        protected AutomationService $automationService,
    ) {}

    public function handle(SaleRefundedForAccounting $event): void
    {
        $this->automationService->handleSaleRefunded($event->sale, $event->refundBatch);
    }
}
