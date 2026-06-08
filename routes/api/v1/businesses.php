<?php

use App\Http\Controllers\Api\BusinessController;
use Illuminate\Support\Facades\Route;

Route::post('/businesses/register', [BusinessController::class, 'store']);

Route::middleware(['auth:sanctum', 'business.active', 'module:settings'])->group(function () {
    Route::get('/businesses/mine', [BusinessController::class, 'mine']);
    Route::put('/businesses/profile', [BusinessController::class, 'updateProfile']);
    Route::get('/businesses/{id}', [BusinessController::class, 'show']);
    Route::put('/businesses/{id}', [BusinessController::class, 'update']);
    Route::get('/businesses/settings', [BusinessController::class, 'settings']);
    Route::put('/businesses/settings', [BusinessController::class, 'updateSettings']);
});
