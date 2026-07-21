<?php

namespace App\Providers;

use App\Repositories\Contracts\PaymentRepositoryInterface;
use App\Repositories\Contracts\SubscriptionScheduledChangeRepositoryInterface;
use App\Repositories\Eloquent\PaymentRepository;
use App\Repositories\Eloquent\SubscriptionScheduledChangeRepository;
use App\Services\Billing\PaymentQuoteService;
use App\Services\Billing\PaymentService;
use App\Services\Billing\SubscriptionProrationCalculator;
use App\Services\Billing\SubscriptionScheduledChangeService;
use App\Services\Contracts\PaymentServiceInterface;
use App\Services\Contracts\SubscriptionScheduledChangeServiceInterface;
use Illuminate\Support\ServiceProvider;

class BillingServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(PaymentRepositoryInterface::class, PaymentRepository::class);
        $this->app->bind(PaymentServiceInterface::class, PaymentService::class);

        $this->app->bind(
            SubscriptionScheduledChangeRepositoryInterface::class,
            SubscriptionScheduledChangeRepository::class,
        );
        $this->app->bind(
            SubscriptionScheduledChangeServiceInterface::class,
            SubscriptionScheduledChangeService::class,
        );

        $this->app->singleton(SubscriptionProrationCalculator::class);
        $this->app->singleton(PaymentQuoteService::class);
    }

    public function boot(): void
    {
        //
    }
}
