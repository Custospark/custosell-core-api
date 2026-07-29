<?php

namespace App\Providers;

use App\Repositories\Contracts\SubscriptionRepositoryInterface;
use App\Repositories\Eloquent\SubscriptionRepository;
use App\Services\Billing\SubscriptionStateMachineService;
use App\Services\Contracts\SubscriptionServiceInterface;
use App\Services\Contracts\SubscriptionStateMachineServiceInterface;
use App\Services\SubscriptionService;
use Illuminate\Support\ServiceProvider;

class SubscriptionServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(
            SubscriptionRepositoryInterface::class,
            SubscriptionRepository::class,
        );

        $this->app->bind(
            SubscriptionServiceInterface::class,
            SubscriptionService::class,
        );

        $this->app->bind(
            SubscriptionStateMachineServiceInterface::class,
            SubscriptionStateMachineService::class,
        );
    }

    public function boot(): void
    {
        //
    }
}
