<?php

use App\Http\Controllers\Api\Platform\PlatformBusinessController;
use App\Http\Controllers\Api\Platform\PlatformOverviewController;
use App\Http\Controllers\Api\Platform\PlatformRoleController;
use App\Http\Controllers\Api\Platform\PlatformUserController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:sanctum', 'business.active'])->prefix('platform')->group(function () {
    Route::middleware(['platform:platform.overview.view'])->group(function () {
        Route::get('/overview', [PlatformOverviewController::class, 'summary']);
        Route::get('/metrics', [PlatformOverviewController::class, 'metrics']);
    });

    Route::middleware(['platform:platform.businesses.view'])->group(function () {
        Route::get('/businesses/stats', [PlatformBusinessController::class, 'stats']);
        Route::get('/businesses', [PlatformBusinessController::class, 'index']);
    });

    Route::middleware(['platform:platform.businesses.manage'])->group(function () {
        Route::patch('/businesses/{id}/status', [PlatformBusinessController::class, 'updateStatus']);
    });

    Route::middleware(['platform:platform.users.view'])->group(function () {
        Route::get('/users', [PlatformUserController::class, 'index']);
        Route::get('/team', [PlatformUserController::class, 'platformTeam']);
    });

    Route::middleware(['platform:platform.users.manage'])->group(function () {
        Route::patch('/users/{id}/status', [PlatformUserController::class, 'updateStatus']);
    });

    Route::middleware(['platform:platform.roles.view'])->group(function () {
        Route::get('/roles', [PlatformRoleController::class, 'index']);
        Route::get('/permissions', [PlatformRoleController::class, 'permissions']);
    });

    Route::middleware(['platform:platform.roles.manage'])->group(function () {
        Route::post('/roles', [PlatformRoleController::class, 'store']);
        Route::put('/roles/{id}', [PlatformRoleController::class, 'update']);
        Route::delete('/roles/{id}', [PlatformRoleController::class, 'destroy']);
        Route::post('/users/{id}/roles', [PlatformUserController::class, 'assignRole']);
        Route::delete('/users/{id}/roles/{role}', [PlatformUserController::class, 'revokeRole']);
    });
});
