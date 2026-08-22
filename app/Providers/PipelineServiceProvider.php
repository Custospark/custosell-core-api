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

        $this->app->bind(
            \App\Repositories\Contracts\PipelineAutomationRuleRepositoryInterface::class,
            \App\Repositories\Eloquent\PipelineAutomationRuleRepository::class,
        );
        $this->app->bind(
            \App\Services\Contracts\PipelineAutomationRuleServiceInterface::class,
            \App\Services\Pipeline\PipelineAutomationRuleService::class,
        );
        $this->app->singleton(\App\Services\Pipeline\PipelineAutomationConditionEvaluator::class);
        $this->app->singleton(\App\Services\Pipeline\PipelineAutomationActionService::class);
        $this->app->singleton(\App\Services\Pipeline\PipelineAutomationSchedulerService::class);
    }

    public function boot(): void
    {
        //
    }
}
