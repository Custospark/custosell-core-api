<?php

use App\Http\Controllers\Api\SalesRepController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:sanctum', 'business.active'])->group(function () {
    // Static earnings routes must be defined before apiResource to avoid {sales_rep} wildcard capture
    Route::get('sales-reps/earnings/all', [SalesRepController::class, 'earningsIndex']);
    Route::get('sales-reps/earnings/mine', [SalesRepController::class, 'myEarnings']);
    Route::apiResource('sales-reps', SalesRepController::class);
    Route::get('sales-reps/{id}/earnings', [SalesRepController::class, 'earnings']);
});
