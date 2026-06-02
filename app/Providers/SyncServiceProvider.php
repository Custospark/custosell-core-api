<?php

namespace App\Providers;

use App\Services\Contracts\SyncServiceInterface;
use App\Services\SyncService;
use Illuminate\Support\ServiceProvider;

class SyncServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(
            SyncServiceInterface::class,
            SyncService::class,
        );
    }

    public function boot(): void
    {
        //
    }
}
