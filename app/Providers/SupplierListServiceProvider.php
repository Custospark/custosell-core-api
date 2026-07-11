<?php

namespace App\Providers;

use App\Services\Contracts\SupplierListServiceInterface;
use App\Services\SupplierListService;
use Illuminate\Support\ServiceProvider;

class SupplierListServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(SupplierListServiceInterface::class, SupplierListService::class);
    }
}
