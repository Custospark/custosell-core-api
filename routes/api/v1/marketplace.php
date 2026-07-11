<?php

use App\Http\Controllers\Api\MarketplaceController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:sanctum', 'business.active', 'module:inventory'])->group(function () {
    Route::get('/marketplace/businesses', [MarketplaceController::class, 'businesses']);
    Route::get('/marketplace/businesses/{id}/products', [MarketplaceController::class, 'products'])->whereNumber('id');

    Route::get('/marketplace/suppliers', [MarketplaceController::class, 'supplierList']);
    Route::post('/marketplace/suppliers', [MarketplaceController::class, 'addSupplier']);
    Route::delete('/marketplace/suppliers/{sellerBusinessId}', [MarketplaceController::class, 'removeSupplier'])
        ->whereNumber('sellerBusinessId');
});
