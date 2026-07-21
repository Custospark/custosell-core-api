<?php

namespace App\Providers;

use App\Services\Payment\GatewayManager;
use App\Services\Payment\GatewayService;
use Illuminate\Support\ServiceProvider;

class PaymentGatewayServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(GatewayManager::class);
        $this->app->singleton(GatewayService::class);
    }

    public function boot(): void
    {
        //
    }
}
