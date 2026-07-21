<?php

namespace App\Console\Commands;

use App\Services\Contracts\SubscriptionServiceInterface;
use Illuminate\Console\Command;

class SubscriptionsSuspendPastDue extends Command
{
    protected $signature = 'subscriptions:suspend-past-due';
    protected $description = 'Suspend subscriptions whose grace period has expired';

    public function handle(SubscriptionServiceInterface $subscriptionService): int
    {
        $count = $subscriptionService->processSuspensions();

        $this->info("Suspended {$count} subscription(s) with expired grace period.");

        return Command::SUCCESS;
    }
}
