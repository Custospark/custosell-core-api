<?php

namespace App\Providers;

use App\Repositories\Contracts\ShiftRepositoryInterface;
use App\Repositories\Eloquent\ShiftRepository;
use App\Services\Contracts\ShiftServiceInterface;
use App\Services\ShiftService;
use Illuminate\Support\ServiceProvider;

class ShiftServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(
            ShiftRepositoryInterface::class,
            ShiftRepository::class,
        );

        $this->app->bind(
            ShiftServiceInterface::class,
            ShiftService::class,
        );
    }

    public function boot(): void
    {
        //
    }
}
