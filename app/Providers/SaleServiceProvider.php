<?php

namespace App\Providers;

use App\Repositories\Contracts\SaleRepositoryInterface;
use App\Repositories\Eloquent\SaleRepository;
use App\Services\Contracts\SaleServiceInterface;
use App\Services\SaleService;
use Illuminate\Support\ServiceProvider;

class SaleServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(
            SaleRepositoryInterface::class,
            SaleRepository::class,
        );

        $this->app->bind(
            SaleServiceInterface::class,
            SaleService::class,
        );
    }

    public function boot(): void
    {
        //
    }
}
