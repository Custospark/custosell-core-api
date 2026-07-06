<?php

namespace App\Providers;

use App\Services\PipelineService;
use Illuminate\Support\ServiceProvider;

class PipelineServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(PipelineService::class);
    }

    public function boot(): void
    {
        //
    }
}
