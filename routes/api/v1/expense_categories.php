<?php

use App\Http\Controllers\Api\ExpenseCategoryController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:sanctum', 'business.active', 'module:expenses'])->group(function () {
    Route::apiResource('expense-categories', ExpenseCategoryController::class);
});
