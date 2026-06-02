<?php

namespace App\Providers;

use App\Repositories\Contracts\CustomerRepositoryInterface;
use App\Repositories\Eloquent\CustomerRepository;
use App\Services\Contracts\CustomerServiceInterface;
use App\Services\CustomerService;
use Illuminate\Support\ServiceProvider;

class CustomerServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(
            CustomerRepositoryInterface::class,
            CustomerRepository::class,
        );

        $this->app->bind(
            CustomerServiceInterface::class,
            CustomerService::class,
        );
    }

    public function boot(): void
    {
        //
    }
}
