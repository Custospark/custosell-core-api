<?php

use App\Http\Controllers\Api\MarketplaceController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:sanctum', 'business.active', 'module:inventory'])->group(function () {
    Route::get('/marketplace/businesses', [MarketplaceController::class, 'businesses']);
    Route::get('/marketplace/businesses/{id}/products', [MarketplaceController::class, 'products'])->whereNumber('id');
});
