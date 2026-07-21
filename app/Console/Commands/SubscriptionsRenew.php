<?php

namespace App\Console\Commands;

use App\Services\Contracts\SubscriptionServiceInterface;
use Illuminate\Console\Command;

class SubscriptionsRenew extends Command
{
    protected $signature = 'subscriptions:renew';
    protected $description = 'Mark active subscriptions with past-due billing dates as past_due with grace period';

    public function handle(SubscriptionServiceInterface $subscriptionService): int
    {
        $count = $subscriptionService->processRenewals();

        $this->info("Marked {$count} subscription(s) as past_due with grace period.");

        return Command::SUCCESS;
    }
}
