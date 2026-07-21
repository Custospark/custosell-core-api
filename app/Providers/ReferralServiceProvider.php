<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;

class ReferralServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(
            \App\Repositories\Contracts\ReferralCodeRepositoryInterface::class,
            \App\Repositories\Eloquent\ReferralCodeRepository::class
        );

        $this->app->bind(
            \App\Repositories\Contracts\SalesRepRepositoryInterface::class,
            \App\Repositories\Eloquent\SalesRepRepository::class
        );

        $this->app->bind(
            \App\Repositories\Contracts\ReferralRepositoryInterface::class,
            \App\Repositories\Eloquent\ReferralRepository::class
        );

        $this->app->bind(
            \App\Services\Contracts\ReferralCodeServiceInterface::class,
            \App\Services\ReferralCodeService::class
        );

        $this->app->bind(
            \App\Services\Contracts\SalesRepServiceInterface::class,
            \App\Services\SalesRepService::class
        );

        $this->app->bind(
            \App\Services\Contracts\ReferralServiceInterface::class,
            \App\Services\ReferralService::class
        );
    }

    public function boot(): void
    {
        //
    }
}
