<?php

declare(strict_types=1);

namespace App\Providers;

use App\Services\Efris\EfrisClient;
use App\Services\Efris\EfrisService;
use App\Services\Efris\EfrisServiceInterface;
use Illuminate\Support\ServiceProvider;

class EfrisServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(EfrisClient::class);
        $this->app->singleton(EfrisServiceInterface::class, EfrisService::class);
    }

    public function boot(): void
    {
        //
    }
}
