<?php

use App\Http\Controllers\Api\StockMovementController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:sanctum', 'business.active', 'subscription.active', 'module:inventory'])->group(function () {
    Route::post('/stock-movements/bulk-delete', [StockMovementController::class, 'bulkDelete']);
    Route::post('/stock-movements/transfer', [StockMovementController::class, 'transfer']);
    Route::apiResource('stock-movements', StockMovementController::class);
});
