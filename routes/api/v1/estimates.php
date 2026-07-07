<?php

use App\Http\Controllers\Api\EstimateController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:sanctum', 'business.active', 'business.owner'])->group(function () {
    Route::get('/estimates/analytics', [EstimateController::class, 'analytics']);

    Route::get('/estimates/templates', [EstimateController::class, 'templates']);
    Route::post('/estimates/templates', [EstimateController::class, 'storeTemplate']);
    Route::get('/estimates/templates/{id}', [EstimateController::class, 'showTemplate'])->whereNumber('id');
    Route::put('/estimates/templates/{id}', [EstimateController::class, 'updateTemplate'])->whereNumber('id');
    Route::patch('/estimates/templates/{id}', [EstimateController::class, 'updateTemplate'])->whereNumber('id');
    Route::delete('/estimates/templates/{id}', [EstimateController::class, 'destroyTemplate'])->whereNumber('id');

    Route::get('/estimates', [EstimateController::class, 'index']);
    Route::post('/estimates', [EstimateController::class, 'store']);
    Route::get('/estimates/{id}', [EstimateController::class, 'show'])->whereNumber('id');
    Route::put('/estimates/{id}', [EstimateController::class, 'update'])->whereNumber('id');
    Route::patch('/estimates/{id}', [EstimateController::class, 'update'])->whereNumber('id');
    Route::delete('/estimates/{id}', [EstimateController::class, 'destroy'])->whereNumber('id');
    Route::post('/estimates/{id}/send', [EstimateController::class, 'send'])->whereNumber('id');
    Route::post('/estimates/{id}/approve', [EstimateController::class, 'approve'])->whereNumber('id');
    Route::post('/estimates/{id}/reject', [EstimateController::class, 'reject'])->whereNumber('id');
    Route::post('/estimates/{id}/email', [EstimateController::class, 'email'])->whereNumber('id');
    Route::get('/estimates/{id}/pdf', [EstimateController::class, 'downloadPdf'])->whereNumber('id');
    Route::post('/estimates/{id}/duplicate', [EstimateController::class, 'duplicate'])->whereNumber('id');
    Route::get('/estimates/{id}/versions', [EstimateController::class, 'versions'])->whereNumber('id');
    Route::post('/estimates/{id}/revision', [EstimateController::class, 'createRevision'])->whereNumber('id');
    Route::post('/estimates/{id}/convert-to-invoice', [EstimateController::class, 'convertToInvoice'])->whereNumber('id');
    Route::post('/estimates/{id}/convert-to-project', [EstimateController::class, 'convertToProject'])->whereNumber('id');
});
