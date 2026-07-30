<?php

namespace App\Providers;

use App\Repositories\Contracts\IncomeSourceRepositoryInterface;
use App\Repositories\Eloquent\IncomeSourceRepository;
use App\Services\Contracts\IncomeSourceServiceInterface;
use App\Services\IncomeSourceService;
use Illuminate\Support\ServiceProvider;

class IncomeSourceServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(
            IncomeSourceRepositoryInterface::class,
            IncomeSourceRepository::class,
        );

        $this->app->bind(
            IncomeSourceServiceInterface::class,
            IncomeSourceService::class,
        );
    }

    public function boot(): void
    {
        //
    }
}
