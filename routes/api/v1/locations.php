<?php

use App\Http\Controllers\Api\LocationController;
use Illuminate\Support\Facades\Route;

/** Branch reads — available to any authenticated business user (branch filters across inventory, expenses, HR). */
Route::middleware(['auth:sanctum', 'business.active', 'subscription.active'])->group(function () {
    Route::get('locations/active', [LocationController::class, 'active']);
    Route::get('locations', [LocationController::class, 'index']);
    Route::get('locations/{location}', [LocationController::class, 'show'])->whereNumber('location');
});

/** Branch management — settings module only. */
Route::middleware(['auth:sanctum', 'business.active', 'subscription.active', 'module:settings'])->group(function () {
    Route::post('locations', [LocationController::class, 'store']);
    Route::put('locations/{location}', [LocationController::class, 'update'])->whereNumber('location');
    Route::patch('locations/{location}', [LocationController::class, 'update'])->whereNumber('location');
    Route::delete('locations/{location}', [LocationController::class, 'destroy'])->whereNumber('location');
    Route::post('locations/{id}/default', [LocationController::class, 'setDefault'])->whereNumber('id');
});
