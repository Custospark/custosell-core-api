<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Symfony\Component\HttpKernel\Exception\HttpException;

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
            'account.usable' => \App\Http\Middleware\EnsureAccountUsable::class,
            'module' => \App\Http\Middleware\EnsureModuleAccess::class,
            'pipeline.access' => \App\Http\Middleware\EnsurePipelineModuleAccess::class,
            'business.owner' => \App\Http\Middleware\EnsureBusinessOwner::class,
            'estimates.workspace' => \App\Http\Middleware\EnsureEstimatesWorkspaceAccess::class,
            'hr.full' => \App\Http\Middleware\EnsureHrFullAccess::class,
            'subscription.active' => \App\Http\Middleware\EnsureActiveSubscription::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Business-rule failures surface as 422 (validation-style) instead of a generic 500,
        // e.g. the plan location-limit guard thrown by LocationService::create.
        // HttpException (incl. NotFoundHttpException 404) extends RuntimeException; let Laravel
        // render those with their real status code instead of collapsing them to 422.
        $exceptions->render(function (RuntimeException $e, \Illuminate\Http\Request $request) {
            if ($e instanceof HttpException) {
                return null;
            }
            if ($request->is('api/*')) {
                if ($e instanceof \Illuminate\Database\QueryException) {
                    \Illuminate\Support\Facades\Log::error('Database error on API request', [
                        'message' => $e->getMessage(),
                        'route' => $request->path(),
                    ]);

                    return response()->json([
                        'message' => 'Something went wrong while saving your data. Please check your entries and try again.',
                        'errors' => ['data' => ['Something went wrong while saving your data. Please check your entries and try again.']],
                    ], 422);
                }

                return response()->json([
                    'message' => $e->getMessage(),
                    'errors' => ['plan_limit' => [$e->getMessage()]],
                ], 422);
            }

            return null;
        });
    })->create();
