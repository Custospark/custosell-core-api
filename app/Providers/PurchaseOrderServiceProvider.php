<?php

namespace App\Providers;

use App\Services\Contracts\PurchaseOrderServiceInterface;
use App\Services\PurchaseOrderService;
use Illuminate\Support\ServiceProvider;

class PurchaseOrderServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(PurchaseOrderServiceInterface::class, PurchaseOrderService::class);
    }
}
