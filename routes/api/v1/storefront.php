<?php

use App\Http\Controllers\Api\StorefrontController;
use Illuminate\Support\Facades\Route;

Route::prefix('storefront')->group(function () {
    Route::get('/discover', [StorefrontController::class, 'discover']);
    Route::get('/categories', [StorefrontController::class, 'categories']);
    Route::get('/shops', [StorefrontController::class, 'shops']);
    Route::get('/my-orders', [StorefrontController::class, 'myOrders'])
        ->middleware('auth:sanctum');
    Route::get('/my-orders/{order}/sale', [StorefrontController::class, 'myOrderSale'])
        ->middleware('auth:sanctum')
        ->whereNumber('order');
    Route::get('/my-orders/{order}/invoice', [StorefrontController::class, 'myOrderInvoice'])
        ->middleware('auth:sanctum')
        ->whereNumber('order');
    Route::get('/my-orders/{order}/invoice/pdf', [StorefrontController::class, 'myOrderInvoicePdf'])
        ->middleware('auth:sanctum')
        ->whereNumber('order');
    Route::post('/my-orders/{order}/cancel', [StorefrontController::class, 'cancelMyOrder'])
        ->middleware('auth:sanctum')
        ->whereNumber('order');
    Route::delete('/my-orders/{order}', [StorefrontController::class, 'deleteMyOrder'])
        ->middleware('auth:sanctum')
        ->whereNumber('order');

    Route::get('/wishlist', [StorefrontController::class, 'wishlist'])
        ->middleware('auth:sanctum');
    Route::post('/wishlist', [StorefrontController::class, 'addToWishlist'])
        ->middleware(['auth:sanctum', 'throttle:60,1']);
    Route::delete('/wishlist/by-product/{product}', [StorefrontController::class, 'removeFromWishlistByProduct'])
        ->middleware(['auth:sanctum', 'throttle:60,1'])
        ->whereNumber('product');
    Route::delete('/wishlist/{wishlist}', [StorefrontController::class, 'removeFromWishlist'])
        ->middleware(['auth:sanctum', 'throttle:60,1'])
        ->whereNumber('wishlist');

    Route::get('/{slug}', [StorefrontController::class, 'show'])->where('slug', '[a-z0-9]+(?:-[a-z0-9]+)*');
    Route::get('/{slug}/products', [StorefrontController::class, 'products'])->where('slug', '[a-z0-9]+(?:-[a-z0-9]+)*');
    Route::post('/{slug}/ratings', [StorefrontController::class, 'rateShop'])
        ->middleware(['auth:sanctum', 'throttle:60,1'])
        ->where('slug', '[a-z0-9]+(?:-[a-z0-9]+)*');
    Route::post('/{slug}/products/{product}/ratings', [StorefrontController::class, 'rateProduct'])
        ->middleware(['auth:sanctum', 'throttle:60,1'])
        ->where('slug', '[a-z0-9]+(?:-[a-z0-9]+)*')
        ->whereNumber('product');
    Route::post('/{slug}/orders', [StorefrontController::class, 'placeOrder'])
        ->middleware(['auth:sanctum', 'throttle:storefront-orders'])
        ->where('slug', '[a-z0-9]+(?:-[a-z0-9]+)*');
});
