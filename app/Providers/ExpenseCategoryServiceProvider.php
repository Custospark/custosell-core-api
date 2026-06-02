<?php

namespace App\Providers;

use App\Repositories\Contracts\ExpenseCategoryRepositoryInterface;
use App\Repositories\Eloquent\ExpenseCategoryRepository;
use App\Services\Contracts\ExpenseCategoryServiceInterface;
use App\Services\ExpenseCategoryService;
use Illuminate\Support\ServiceProvider;

class ExpenseCategoryServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(
            ExpenseCategoryRepositoryInterface::class,
            ExpenseCategoryRepository::class,
        );

        $this->app->bind(
            ExpenseCategoryServiceInterface::class,
            ExpenseCategoryService::class,
        );
    }

    public function boot(): void
    {
        //
    }
}
