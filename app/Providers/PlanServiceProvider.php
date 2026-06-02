<?php

namespace App\Providers;

use App\Repositories\Contracts\PlanRepositoryInterface;
use App\Repositories\Eloquent\PlanRepository;
use App\Services\Contracts\PlanServiceInterface;
use App\Services\PlanService;
use Illuminate\Support\ServiceProvider;

class PlanServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(
            PlanRepositoryInterface::class,
            PlanRepository::class,
        );

        $this->app->bind(
            PlanServiceInterface::class,
            PlanService::class,
        );
    }

    public function boot(): void
    {
        //
    }
}
