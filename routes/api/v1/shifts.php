<?php

use App\Http\Controllers\Api\ShiftController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:sanctum', 'business.active'])->group(function () {
    Route::get('/shifts/active', [ShiftController::class, 'active']);
    Route::apiResource('shifts', ShiftController::class);
});
