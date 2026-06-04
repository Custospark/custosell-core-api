<?php

use App\Http\Controllers\Api\ProductController;
use App\Http\Controllers\Api\ProductImportController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth:sanctum')->group(function () {
    Route::get('/products/active', [ProductController::class, 'active']);
    Route::get('/products/low-stock', [ProductController::class, 'lowStock']);
    Route::get('/products/import-template', [ProductImportController::class, 'downloadTemplate']);
    Route::post('/products/import', [ProductImportController::class, 'import']);
    Route::get('/products/export', [ProductController::class, 'export']);
    Route::get('/products/{product}/stock-movements', [ProductController::class, 'stockMovements']);
    Route::post('/products/bulk-delete', [ProductController::class, 'bulkDelete']);
    Route::apiResource('products', ProductController::class);
});
