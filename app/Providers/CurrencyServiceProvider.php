<?php

namespace App\Providers;

use App\Services\Currency\Contracts\CurrencyExchangeServiceInterface;
use App\Services\Currency\CurrencyExchangeService;
use Illuminate\Support\ServiceProvider;

class CurrencyServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(
            CurrencyExchangeServiceInterface::class,
            CurrencyExchangeService::class,
        );
    }

    public function boot(): void
    {
        //
    }
}
