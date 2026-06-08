<?php

use App\Http\Controllers\Api\CustomerController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:sanctum', 'business.active', 'module:customers'])->group(function () {
    Route::apiResource('customers', CustomerController::class);
    Route::get('customers/{customer}/purchases', [CustomerController::class, 'purchases']);
});
