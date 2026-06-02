<?php

namespace App\Providers;

use App\Repositories\Contracts\ExpenseRepositoryInterface;
use App\Repositories\Eloquent\ExpenseRepository;
use App\Services\Contracts\ExpenseServiceInterface;
use App\Services\ExpenseService;
use Illuminate\Support\ServiceProvider;

class ExpenseServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(
            ExpenseRepositoryInterface::class,
            ExpenseRepository::class,
        );

        $this->app->bind(
            ExpenseServiceInterface::class,
            ExpenseService::class,
        );
    }

    public function boot(): void
    {
        //
    }
}
