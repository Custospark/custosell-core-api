<?php

namespace App\Providers;

use App\Services\Contracts\OrderServiceInterface;
use App\Services\OrderService;
use Illuminate\Support\ServiceProvider;

class OrderServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(OrderServiceInterface::class, OrderService::class);
    }
}
