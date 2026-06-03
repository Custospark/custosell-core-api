<?php

use App\Http\Controllers\Api\ProductImportController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth:sanctum')->group(function () {
    Route::post('/products/import', [ProductImportController::class, 'import']);
    Route::get('/products/import-template', [ProductImportController::class, 'downloadTemplate']);
});
