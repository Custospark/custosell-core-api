<?php

use App\Http\Controllers\Api\SalesRepController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:sanctum', 'business.active'])->group(function () {
    Route::apiResource('sales-reps', SalesRepController::class);
});
