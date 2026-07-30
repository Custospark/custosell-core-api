<?php

use App\Http\Controllers\Api\IncomeSourceAttachmentController;
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

    Route::post('/income-sources/{incomeSourceId}/attachments', [IncomeSourceAttachmentController::class, 'store'])->whereNumber('incomeSourceId');
    Route::post('/income-sources/{incomeSourceId}/attachments/link', [IncomeSourceAttachmentController::class, 'storeLink'])->whereNumber('incomeSourceId');
    Route::delete('/income-source-attachments/{id}', [IncomeSourceAttachmentController::class, 'destroy'])->whereNumber('id');
});
