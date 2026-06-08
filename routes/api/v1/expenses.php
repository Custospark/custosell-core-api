<?php

use App\Http\Controllers\Api\ExpenseController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:sanctum', 'business.active'])->group(function () {
    Route::get('/expenses/summary', [ExpenseController::class, 'summary']);
    Route::get('/expenses/export', [ExpenseController::class, 'export']);
    Route::get('/expenses/by-shift/{shiftId}', [ExpenseController::class, 'byShift']);
    Route::apiResource('expenses', ExpenseController::class);
});
