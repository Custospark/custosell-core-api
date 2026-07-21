<?php

namespace App\Console\Commands;

use App\Services\Contracts\SubscriptionServiceInterface;
use Illuminate\Console\Command;

class SubscriptionsExpireTrials extends Command
{
    protected $signature = 'subscriptions:expire-trials';
    protected $description = 'Expire subscriptions whose trial period has ended without activation';

    public function handle(SubscriptionServiceInterface $subscriptionService): int
    {
        $count = $subscriptionService->processExpiredTrials();

        $this->info("Expired {$count} trial subscription(s).");

        return Command::SUCCESS;
    }
}
