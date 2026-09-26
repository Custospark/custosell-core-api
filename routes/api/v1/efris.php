<?php

use App\Http\Controllers\Api\EfrisStatusController;
use App\Http\Controllers\Api\FiscalCredentialController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:sanctum', 'business.active', 'subscription.active'])->group(function () {
    Route::get('/efris/status', EfrisStatusController::class);
    Route::get('/fiscal-credentials', [FiscalCredentialController::class, 'index']);
    Route::post('/fiscal-credentials', [FiscalCredentialController::class, 'store']);
    Route::get('/fiscal-credentials/{id}', [FiscalCredentialController::class, 'show'])->whereNumber('id');
    Route::put('/fiscal-credentials/{id}', [FiscalCredentialController::class, 'update'])->whereNumber('id');
    Route::patch('/fiscal-credentials/{id}', [FiscalCredentialController::class, 'update'])->whereNumber('id');
    Route::delete('/fiscal-credentials/{id}', [FiscalCredentialController::class, 'destroy'])->whereNumber('id');
});
