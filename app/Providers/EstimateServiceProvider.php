<?php

namespace App\Providers;

use App\Repositories\Contracts\EstimateRepositoryInterface;
use App\Repositories\Contracts\ProjectRepositoryInterface;
use App\Repositories\Eloquent\EstimateRepository;
use App\Repositories\Eloquent\ProjectRepository;
use App\Services\Contracts\EstimateServiceInterface;
use App\Services\Contracts\ProjectServiceInterface;
use App\Services\EstimateService;
use App\Services\ProjectService;
use Illuminate\Support\ServiceProvider;

class EstimateServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(EstimateRepositoryInterface::class, EstimateRepository::class);
        $this->app->bind(ProjectRepositoryInterface::class, ProjectRepository::class);
        $this->app->bind(ProjectServiceInterface::class, ProjectService::class);
        $this->app->bind(EstimateServiceInterface::class, EstimateService::class);
    }

    public function boot(): void
    {
        //
    }
}
