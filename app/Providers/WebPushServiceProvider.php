<?php

namespace App\Providers;

use App\Services\WebPush\Contracts\WebPushServiceInterface;
use App\Services\WebPush\WebPushService;
use Illuminate\Support\ServiceProvider;

class WebPushServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(WebPushServiceInterface::class, WebPushService::class);
    }

    public function boot(): void
    {
        //
    }
}