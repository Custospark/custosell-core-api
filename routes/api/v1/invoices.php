<?php

use App\Http\Controllers\Api\InvoiceController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:sanctum', 'business.active', 'subscription.active', 'module:sales'])->group(function () {
    Route::get('/invoices', [InvoiceController::class, 'index']);
    Route::post('/invoices', [InvoiceController::class, 'store']);
    Route::get('/invoices/{id}', [InvoiceController::class, 'show'])->whereNumber('id');
    Route::put('/invoices/{id}', [InvoiceController::class, 'update'])->whereNumber('id');
    Route::patch('/invoices/{id}', [InvoiceController::class, 'update'])->whereNumber('id');
    Route::delete('/invoices/{id}', [InvoiceController::class, 'destroy'])->whereNumber('id');
    Route::post('/invoices/{id}/payment', [InvoiceController::class, 'recordPayment'])->whereNumber('id');
    Route::post('/invoices/{id}/send', [InvoiceController::class, 'send'])->whereNumber('id');
    Route::post('/invoices/{id}/email', [InvoiceController::class, 'email'])->whereNumber('id');
    Route::get('/invoices/{id}/pdf', [InvoiceController::class, 'downloadPdf'])->whereNumber('id');
});
