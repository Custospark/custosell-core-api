<?php

namespace App\Providers;

use App\Repositories\Contracts\BusinessRepositoryInterface;
use App\Repositories\Eloquent\BusinessRepository;
use App\Services\Contracts\BusinessServiceInterface;
use App\Services\BusinessService;
use Illuminate\Support\ServiceProvider;

class BusinessServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(
            BusinessRepositoryInterface::class,
            BusinessRepository::class,
        );

        $this->app->bind(
            BusinessServiceInterface::class,
            BusinessService::class,
        );
    }

    public function boot(): void
    {
        //
    }
}
