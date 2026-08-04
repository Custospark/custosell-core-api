<?php

namespace App\Providers;

use App\Repositories\Contracts\BusinessSocialLinkRepositoryInterface;
use App\Repositories\Eloquent\BusinessSocialLinkRepository;
use App\Services\BusinessSocialLinkService;
use App\Services\Contracts\BusinessSocialLinkServiceInterface;
use Illuminate\Support\ServiceProvider;

class BusinessSocialLinkServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(
            BusinessSocialLinkRepositoryInterface::class,
            BusinessSocialLinkRepository::class,
        );

        $this->app->bind(
            BusinessSocialLinkServiceInterface::class,
            BusinessSocialLinkService::class,
        );
    }

    public function boot(): void
    {
        //
    }
}