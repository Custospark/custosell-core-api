<?php

namespace App\Providers;

use App\Services\PipelineService;
use Illuminate\Support\ServiceProvider;

class PipelineServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(PipelineService::class);
        $this->app->singleton(\App\Services\Pipeline\PipelineBoardSeedService::class);
        $this->app->singleton(\App\Services\Pipeline\PipelineNotificationService::class);
        $this->app->singleton(\App\Services\Pipeline\PipelineCollaborationService::class);
        $this->app->singleton(\App\Services\Pipeline\PipelineBoardLookupService::class);
        $this->app->singleton(\App\Services\Pipeline\PipelineBoardPermissionService::class);
        $this->app->singleton(\App\Services\Pipeline\PipelineBoardService::class);
        $this->app->singleton(\App\Services\Pipeline\PipelineSourceService::class);
        $this->app->singleton(\App\Services\Pipeline\PipelineLabelService::class);
        $this->app->singleton(\App\Services\Pipeline\PipelineMemberService::class);
        $this->app->singleton(\App\Services\Pipeline\PipelineStageService::class);
        $this->app->singleton(\App\Services\Pipeline\PipelineLeadService::class);
        $this->app->singleton(\App\Services\Pipeline\PipelineCalendarService::class);
        $this->app->singleton(\App\Services\Pipeline\PipelineChecklistService::class);
        $this->app->singleton(\App\Services\Pipeline\PipelineLeadLinkService::class);
        $this->app->singleton(\App\Services\Pipeline\PipelineActivityService::class);
    }

    public function boot(): void
    {
        //
    }
}
