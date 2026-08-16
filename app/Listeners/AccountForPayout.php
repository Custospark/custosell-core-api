<?php

namespace App\Listeners;

use App\Events\PayoutCompletedForAccounting;
use App\Services\CompanyAccountingService;
use Illuminate\Support\Facades\Log;

class AccountForPayout
{
    public function __construct(
        protected CompanyAccountingService $companyAccounting,
    ) {}

    public function handle(PayoutCompletedForAccounting $event): void
    {
        try {
            $this->companyAccounting->accountForPayout($event->payout);
        } catch (\Throwable $e) {
            Log::error("Company books: failed to journal payout {$event->payout->id}: {$e->getMessage()}", [
                'payout_id' => $event->payout->id,
                'exception' => $e,
            ]);
        }
    }
}
