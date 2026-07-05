<?php

namespace App\Providers;

use App\Repositories\Contracts\InvoiceRepositoryInterface;
use App\Repositories\Eloquent\InvoiceRepository;
use App\Services\Contracts\InvoiceServiceInterface;
use App\Services\InvoiceService;
use Illuminate\Support\ServiceProvider;

class InvoiceServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(
            InvoiceRepositoryInterface::class,
            InvoiceRepository::class,
        );

        $this->app->bind(
            InvoiceServiceInterface::class,
            InvoiceService::class,
        );
    }

    public function boot(): void
    {
        //
    }
}
