<?php

namespace App\Providers;

use App\Repositories\Contracts\PersonalBudgetRepositoryInterface;
use App\Repositories\Eloquent\PersonalBudgetRepository;
use App\Services\Contracts\PersonalBudgetServiceInterface;
use App\Services\PersonalBudgetService;
use Illuminate\Support\ServiceProvider;

class PersonalBudgetServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(
            PersonalBudgetRepositoryInterface::class,
            PersonalBudgetRepository::class,
        );

        $this->app->bind(
            PersonalBudgetServiceInterface::class,
            PersonalBudgetService::class,
        );
    }

    public function boot(): void
    {
        //
    }
}