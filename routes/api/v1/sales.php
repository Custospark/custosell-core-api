<?php

use App\Http\Controllers\Api\SaleController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth:sanctum')->group(function () {
    Route::get('/sales/daily', [SaleController::class, 'daily']);
    Route::post('/sales/{sale}/refund', [SaleController::class, 'refund']);
    Route::apiResource('sales', SaleController::class);
});
