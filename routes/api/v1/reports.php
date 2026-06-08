<?php

use App\Http\Controllers\Api\ReportsController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:sanctum', 'business.active'])->group(function () {
    Route::get('/reports/shift-close', [ReportsController::class, 'shiftClose']);
});

Route::middleware(['auth:sanctum', 'business.active', 'permission:reports.view'])->group(function () {
    Route::get('/reports/business-summary', [ReportsController::class, 'businessSummary']);
    Route::get('/reports/daily-sales', [ReportsController::class, 'dailySales']);
    Route::get('/reports/sales-trend', [ReportsController::class, 'salesTrend']);
    Route::get('/reports/expenses', [ReportsController::class, 'expenses']);
    Route::get('/reports/inventory', [ReportsController::class, 'inventory']);
    Route::get('/reports/payment-breakdown', [ReportsController::class, 'paymentBreakdown']);
    Route::get('/reports/shift-reconciliation', [ReportsController::class, 'shiftReconciliation']);
    Route::get('/reports/product-performance', [ReportsController::class, 'productPerformance']);
});
