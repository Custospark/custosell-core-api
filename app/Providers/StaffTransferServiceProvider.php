<?php

namespace App\Providers;

use App\Repositories\Contracts\StaffTransferRepositoryInterface;
use App\Repositories\Eloquent\StaffTransferRepository;
use App\Services\Contracts\StaffTransferServiceInterface;
use App\Services\StaffTransferService;
use Illuminate\Support\ServiceProvider;

class StaffTransferServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(
            StaffTransferRepositoryInterface::class,
            StaffTransferRepository::class,
        );

        $this->app->bind(
            StaffTransferServiceInterface::class,
            StaffTransferService::class,
        );
    }

    public function boot(): void
    {
        //
    }
}
