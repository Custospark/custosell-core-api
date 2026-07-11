<?php

use App\Http\Controllers\Api\SaleController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:sanctum', 'business.active', 'module:sales'])->group(function () {
    Route::get('/sales/daily', [SaleController::class, 'daily']);
    Route::get('/sales/by-shift/{shiftId}', [SaleController::class, 'byShift']);
    Route::post('/sales/batch', [SaleController::class, 'batch']);
    Route::post('/sales/bulk-delete', [SaleController::class, 'bulkDelete']);
    Route::post('/sales/{id}/refund', [SaleController::class, 'refund'])->whereNumber('id');
    Route::post('/sales/{id}/payment', [SaleController::class, 'recordPayment'])->whereNumber('id');
    Route::patch('/sales/{id}/customer', [SaleController::class, 'assignCustomer'])->whereNumber('id');
    Route::get('/sales/{id}/pdf', [SaleController::class, 'downloadPdf'])->whereNumber('id');
    Route::post('/sales/{id}/email', [SaleController::class, 'email'])->whereNumber('id');
    Route::apiResource('sales', SaleController::class);
});
