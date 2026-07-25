<?php

use App\Http\Controllers\Api\SalesRepController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:sanctum', 'business.active'])->group(function () {
    Route::get('sales-reps/import-template', [SalesRepController::class, 'downloadTemplate']);
    Route::post('sales-reps/import', [SalesRepController::class, 'import']);
    Route::get('sales-reps/earnings/all', [SalesRepController::class, 'earningsIndex']);
    Route::get('sales-reps/earnings/mine', [SalesRepController::class, 'myEarnings']);
    Route::apiResource('sales-reps', SalesRepController::class);
    Route::get('sales-reps/{id}/earnings', [SalesRepController::class, 'earnings']);
    Route::get('sales-reps/{id}/payouts', [SalesRepController::class, 'payouts']);
    Route::post('sales-reps/{id}/payouts', [SalesRepController::class, 'recordPayout']);
});
