<?php

use App\Http\Controllers\Api\ReportsController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth:sanctum')->group(function () {
    Route::get('/reports/daily-sales', [ReportsController::class, 'dailySales']);
    Route::get('/reports/sales-trend', [ReportsController::class, 'salesTrend']);
    Route::get('/reports/expenses', [ReportsController::class, 'expenses']);
    Route::get('/reports/inventory', [ReportsController::class, 'inventory']);
    Route::get('/reports/payment-breakdown', [ReportsController::class, 'paymentBreakdown']);
});
