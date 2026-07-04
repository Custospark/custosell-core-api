<?php

namespace App\Listeners;

use App\Events\ExpenseCreatedForAccounting;
use App\Services\AutomationService;

class CreateJournalEntryForExpense
{
    public function __construct(
        protected AutomationService $automationService,
    ) {}

    public function handle(ExpenseCreatedForAccounting $event): void
    {
        $this->automationService->handleExpenseCreated($event->expense);
    }
}
