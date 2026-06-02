<?php

use App\Http\Controllers\Api\StockMovementController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth:sanctum')->group(function () {
    Route::apiResource('stock-movements', StockMovementController::class);
});
