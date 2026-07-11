<?php

use App\Http\Controllers\Api\PurchaseOrderController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:sanctum', 'business.active', 'module:inventory'])->group(function () {
    Route::get('/purchase-orders/incoming', [PurchaseOrderController::class, 'incoming']);
    Route::get('/purchase-orders', [PurchaseOrderController::class, 'index']);
    Route::post('/purchase-orders', [PurchaseOrderController::class, 'store']);
    Route::get('/purchase-orders/{id}', [PurchaseOrderController::class, 'show'])->whereNumber('id');
    Route::put('/purchase-orders/{id}', [PurchaseOrderController::class, 'update'])->whereNumber('id');
    Route::post('/purchase-orders/{id}/submit', [PurchaseOrderController::class, 'submit'])->whereNumber('id');
    Route::post('/purchase-orders/{id}/cancel', [PurchaseOrderController::class, 'cancel'])->whereNumber('id');
    Route::post('/purchase-orders/{id}/accept', [PurchaseOrderController::class, 'accept'])->whereNumber('id');
    Route::post('/purchase-orders/{id}/reject', [PurchaseOrderController::class, 'reject'])->whereNumber('id');
    Route::post('/purchase-orders/{id}/fulfill', [PurchaseOrderController::class, 'fulfill'])->whereNumber('id');
    Route::post('/purchase-orders/{id}/receive', [PurchaseOrderController::class, 'receive'])->whereNumber('id');
});
