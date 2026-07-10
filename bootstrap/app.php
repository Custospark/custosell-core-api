<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'permission' => \App\Http\Middleware\EnsurePermission::class,
            'platform' => \App\Http\Middleware\EnsurePlatformAccess::class,
            'business.active' => \App\Http\Middleware\EnsureBusinessActive::class,
            'module' => \App\Http\Middleware\EnsureModuleAccess::class,
            'pipeline.access' => \App\Http\Middleware\EnsurePipelineModuleAccess::class,
            'business.owner' => \App\Http\Middleware\EnsureBusinessOwner::class,
            'estimates.workspace' => \App\Http\Middleware\EnsureEstimatesWorkspaceAccess::class,
            'hr.full' => \App\Http\Middleware\EnsureHrFullAccess::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
