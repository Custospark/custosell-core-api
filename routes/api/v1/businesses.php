<?php

use App\Http\Controllers\Api\BusinessAccountController;
use App\Http\Controllers\Api\BusinessController;
use App\Http\Controllers\Api\BusinessExportController;
use Illuminate\Support\Facades\Route;

Route::post('/businesses/register', [BusinessController::class, 'store']);

Route::middleware(['auth:sanctum', 'business.active', 'module:settings'])->group(function () {
    // Static routes before parameterized {id} routes
    Route::get('/businesses/mine', [BusinessController::class, 'mine']);
    Route::put('/businesses/profile', [BusinessController::class, 'updateProfile']);
    Route::get('/businesses/slug-available', [BusinessController::class, 'slugAvailable']);
    Route::patch('/businesses/storefront-profile', [BusinessController::class, 'updateStorefrontProfile']);
    Route::get('/businesses/settings', [BusinessController::class, 'settings']);
    Route::put('/businesses/settings', [BusinessController::class, 'updateSettings']);
    Route::get('/businesses/export', [BusinessExportController::class, 'export']);
    Route::delete('/businesses/account', [BusinessAccountController::class, 'destroy']);
    // Parameterized routes
    Route::get('/businesses/{id}', [BusinessController::class, 'show']);
    Route::put('/businesses/{id}', [BusinessController::class, 'update']);
});

Route::middleware(['auth:sanctum', 'business.active', 'module:inventory'])->group(function () {
    Route::patch('/businesses/supply-profile', [BusinessController::class, 'updateSupplyProfile']);
});
