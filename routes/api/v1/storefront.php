<?php

use App\Http\Controllers\Api\StorefrontController;
use Illuminate\Support\Facades\Route;

Route::prefix('storefront')->group(function () {
    Route::get('/discover', [StorefrontController::class, 'discover']);
    Route::get('/categories', [StorefrontController::class, 'categories']);
    Route::get('/shops', [StorefrontController::class, 'shops']);
    Route::get('/my-orders', [StorefrontController::class, 'myOrders'])
        ->middleware('auth:sanctum');
    Route::get('/{slug}', [StorefrontController::class, 'show'])->where('slug', '[a-z0-9]+(?:-[a-z0-9]+)*');
    Route::get('/{slug}/products', [StorefrontController::class, 'products'])->where('slug', '[a-z0-9]+(?:-[a-z0-9]+)*');
    Route::post('/{slug}/products/{product}/ratings', [StorefrontController::class, 'rateProduct'])
        ->middleware(['auth:sanctum', 'throttle:60,1'])
        ->where('slug', '[a-z0-9]+(?:-[a-z0-9]+)*')
        ->whereNumber('product');
    Route::post('/{slug}/orders', [StorefrontController::class, 'placeOrder'])
        ->middleware(['auth:sanctum', 'throttle:storefront-orders'])
        ->where('slug', '[a-z0-9]+(?:-[a-z0-9]+)*');
});