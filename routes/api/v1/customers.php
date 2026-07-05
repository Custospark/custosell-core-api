<?php

use App\Http\Controllers\Api\CustomerController;
use Illuminate\Support\Facades\Route;

/** Customer list for POS — sales staff can pick customers at checkout. */
Route::middleware(['auth:sanctum', 'business.active', 'module:sales,customers'])->group(function () {
    Route::get('/customers', [CustomerController::class, 'index']);
    Route::post('/customers/resolve', [CustomerController::class, 'resolve']);
});

Route::middleware(['auth:sanctum', 'business.active', 'module:customers'])->group(function () {
    Route::post('/customers', [CustomerController::class, 'store']);
    Route::get('/customers/{customer}', [CustomerController::class, 'show']);
    Route::put('/customers/{customer}', [CustomerController::class, 'update']);
    Route::patch('/customers/{customer}', [CustomerController::class, 'update']);
    Route::delete('/customers/{customer}', [CustomerController::class, 'destroy']);
    Route::get('/customers/{customer}/purchases', [CustomerController::class, 'purchases']);
});
