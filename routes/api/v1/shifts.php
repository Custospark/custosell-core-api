<?php

use App\Http\Controllers\Api\ShiftController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:sanctum', 'business.active', 'module:sales'])->group(function () {
    Route::get('/shifts/active', [ShiftController::class, 'active']);
    Route::get('/shifts/{shiftId}/payments', [ShiftController::class, 'payments'])->whereNumber('shiftId');
    Route::apiResource('shifts', ShiftController::class);
});
