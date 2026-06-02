<?php

use App\Http\Controllers\Api\SaleItemController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth:sanctum')->group(function () {
    Route::apiResource('sale-items', SaleItemController::class);
});
