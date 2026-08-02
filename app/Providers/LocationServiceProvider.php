<?php

namespace App\Providers;

use App\Repositories\Contracts\LocationRepositoryInterface;
use App\Repositories\Eloquent\LocationRepository;
use App\Services\Contracts\LocationServiceInterface;
use App\Services\LocationService;
use Illuminate\Support\ServiceProvider;

class LocationServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(
            LocationRepositoryInterface::class,
            LocationRepository::class,
        );

        $this->app->bind(
            LocationServiceInterface::class,
            LocationService::class,
        );
    }

    public function boot(): void
    {
        //
    }
}
