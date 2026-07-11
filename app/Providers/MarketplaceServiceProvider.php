<?php

namespace App\Providers;

use App\Services\Contracts\MarketplaceServiceInterface;
use App\Services\MarketplaceService;
use Illuminate\Support\ServiceProvider;

class MarketplaceServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(MarketplaceServiceInterface::class, MarketplaceService::class);
    }
}
