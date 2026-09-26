<?php

namespace App\Providers;

use App\Repositories\Contracts\FiscalCredentialRepositoryInterface;
use App\Repositories\Eloquent\FiscalCredentialRepository;
use App\Services\Contracts\FiscalCredentialServiceInterface;
use App\Services\FiscalCredentialService;
use Illuminate\Support\ServiceProvider;

class FiscalCredentialServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(
            FiscalCredentialRepositoryInterface::class,
            FiscalCredentialRepository::class,
        );

        $this->app->bind(
            FiscalCredentialServiceInterface::class,
            FiscalCredentialService::class,
        );
    }

    public function boot(): void
    {
        //
    }
}
