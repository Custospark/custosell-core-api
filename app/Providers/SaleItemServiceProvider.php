<?php

namespace App\Providers;

use App\Repositories\Contracts\SaleItemRepositoryInterface;
use App\Repositories\Eloquent\SaleItemRepository;
use App\Services\Contracts\SaleItemServiceInterface;
use App\Services\SaleItemService;
use Illuminate\Support\ServiceProvider;

class SaleItemServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(
            SaleItemRepositoryInterface::class,
            SaleItemRepository::class,
        );

        $this->app->bind(
            SaleItemServiceInterface::class,
            SaleItemService::class,
        );
    }

    public function boot(): void
    {
        //
    }
}
