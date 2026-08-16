<?php

namespace App\Listeners;

use App\Events\SubscriptionPaymentCompletedForAccounting;
use App\Services\CompanyAccountingService;
use Illuminate\Support\Facades\Log;

class AccountForSubscriptionPayment
{
    public function __construct(
        protected CompanyAccountingService $companyAccounting,
    ) {}

    public function handle(SubscriptionPaymentCompletedForAccounting $event): void
    {
        try {
            $this->companyAccounting->accountForSubscriptionPayment($event->payment);
        } catch (\Throwable $e) {
            Log::error("Company books: failed to journal subscription payment {$event->payment->id}: {$e->getMessage()}", [
                'payment_id' => $event->payment->id,
                'exception' => $e,
            ]);
        }
    }
}
