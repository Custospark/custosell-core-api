<?php

use App\Http\Controllers\Api\PaymentController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:sanctum', 'business.active', 'module:sales'])->group(function () {
    Route::get('/payments/{id}', [PaymentController::class, 'show'])->whereNumber('id');
    Route::get('/payments/{id}/receipt', [PaymentController::class, 'downloadReceiptPdf'])->whereNumber('id');
    Route::post('/payments/{id}/email', [PaymentController::class, 'emailReceipt'])->whereNumber('id');
});
