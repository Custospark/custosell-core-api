<?php

namespace App\Providers;

use App\Repositories\Contracts\StockMovementRepositoryInterface;
use App\Repositories\Eloquent\StockMovementRepository;
use App\Services\Contracts\StockMovementServiceInterface;
use App\Services\StockMovementService;
use Illuminate\Support\ServiceProvider;

class StockMovementServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(
            StockMovementRepositoryInterface::class,
            StockMovementRepository::class,
        );

        $this->app->bind(
            StockMovementServiceInterface::class,
            StockMovementService::class,
        );
    }

    public function boot(): void
    {
        //
    }
}
