<?php

use App\Http\Controllers\Api\IncomeSourceController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:sanctum', 'business.active', 'subscription.active', 'module:expenses'])->group(function () {
    Route::get('/income-sources', [IncomeSourceController::class, 'index']);
    Route::post('/income-sources', [IncomeSourceController::class, 'store']);
    Route::get('/income-sources/summary', [IncomeSourceController::class, 'summary']);
    Route::get('/income-sources/{incomeSource}', [IncomeSourceController::class, 'show'])->whereNumber('incomeSource');
    Route::put('/income-sources/{incomeSource}', [IncomeSourceController::class, 'update'])->whereNumber('incomeSource');
    Route::patch('/income-sources/{incomeSource}', [IncomeSourceController::class, 'update'])->whereNumber('incomeSource');
    Route::delete('/income-sources/{incomeSource}', [IncomeSourceController::class, 'destroy'])->whereNumber('incomeSource');
});
