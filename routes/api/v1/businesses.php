<?php

use App\Http\Controllers\Api\BusinessController;
use Illuminate\Support\Facades\Route;

Route::post('/businesses/register', [BusinessController::class, 'store']);

Route::middleware('auth:sanctum')->group(function () {
    Route::get('/businesses/mine', [BusinessController::class, 'mine']);
    Route::get('/businesses/{business}', [BusinessController::class, 'show']);
    Route::put('/businesses/{business}', [BusinessController::class, 'update']);
    Route::get('/businesses/settings', [BusinessController::class, 'settings']);
    Route::put('/businesses/settings', [BusinessController::class, 'updateSettings']);
});
