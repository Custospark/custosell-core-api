<?php

use App\Http\Controllers\Api\LocationController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:sanctum', 'business.active', 'subscription.active', 'module:settings'])->group(function () {
    Route::get('locations/active', [LocationController::class, 'active']);
    Route::post('locations/{id}/default', [LocationController::class, 'setDefault']);
    Route::apiResource('locations', LocationController::class);
});
