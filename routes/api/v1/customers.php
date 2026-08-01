<?php

use App\Http\Controllers\Api\CustomerController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:sanctum', 'business.active', 'subscription.active'])->group(function () {
    Route::get('/customers', [CustomerController::class, 'index']);
    Route::get('/customers/overview', [CustomerController::class, 'overview']);
    Route::post('/customers/resolve', [CustomerController::class, 'resolve']);
    Route::post('/customers', [CustomerController::class, 'store']);
    Route::get('/customers/{customer}', [CustomerController::class, 'show']);
    Route::put('/customers/{customer}', [CustomerController::class, 'update']);
    Route::patch('/customers/{customer}', [CustomerController::class, 'update']);
    Route::delete('/customers/{customer}', [CustomerController::class, 'destroy']);
    Route::get('/customers/{customer}/purchases', [CustomerController::class, 'purchases']);
});
