<?php

use App\Http\Controllers\Api\CreditController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:sanctum', 'business.active'])->group(function () {
    Route::prefix('credits')->group(function () {
        Route::get('/balance', [CreditController::class, 'balance']);
        Route::get('/history', [CreditController::class, 'history']);
    });
});
