<?php

namespace App\Console\Commands;

use App\Services\Contracts\SubscriptionServiceInterface;
use Illuminate\Console\Command;

class SubscriptionsCancelAtPeriodEnd extends Command
{
    protected $signature = 'subscriptions:cancel-at-period-end';
    protected $description = 'Cancel subscriptions that have cancel_at_period_end flag and past billing date';

    public function handle(SubscriptionServiceInterface $subscriptionService): int
    {
        $count = $subscriptionService->processCancelAtPeriodEnd();

        $this->info("Cancelled {$count} subscription(s) at period end.");

        return Command::SUCCESS;
    }
}
