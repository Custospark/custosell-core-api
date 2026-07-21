<?php

use App\Http\Controllers\Api\ProductController;
use App\Http\Controllers\Api\ProductImportController;
use Illuminate\Support\Facades\Route;

/** POS catalog — sales staff need active products without full inventory module access. */
Route::middleware(['auth:sanctum', 'business.active', 'subscription.active', 'module:sales,inventory'])->group(function () {
    Route::get('/products/active', [ProductController::class, 'active']);
});

Route::middleware(['auth:sanctum', 'business.active', 'subscription.active', 'module:inventory'])->group(function () {
    Route::get('/products/low-stock', [ProductController::class, 'lowStock']);
    Route::get('/products/import-template', [ProductImportController::class, 'downloadTemplate']);
    Route::post('/products/import', [ProductImportController::class, 'import']);
    Route::get('/products/export', [ProductController::class, 'export']);
    Route::get('/products/{product}/stock-movements', [ProductController::class, 'stockMovements']);
    Route::post('/products/bulk-delete', [ProductController::class, 'bulkDelete']);
    Route::patch('/products/{id}/supply-listing', [ProductController::class, 'updateSupplyListing'])->whereNumber('id');
    Route::patch('/products/{id}/storefront-listing', [ProductController::class, 'updateStorefrontListing'])->whereNumber('id');
    Route::post('/products/{id}/image', [ProductController::class, 'uploadImage'])->whereNumber('id');
    Route::apiResource('products', ProductController::class);
});
