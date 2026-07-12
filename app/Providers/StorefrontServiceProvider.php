<?php

declare(strict_types=1);

namespace App\Providers;

use App\Services\Storefront\StorefrontService;
use Illuminate\Support\ServiceProvider;

class StorefrontServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(StorefrontService::class);
    }
}
