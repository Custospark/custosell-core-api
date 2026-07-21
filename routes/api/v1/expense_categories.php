<?php

use App\Http\Controllers\Api\ExpenseCategoryController;
use Illuminate\Support\Facades\Route;

/** Category list for recording shift expenses at POS. */
Route::middleware(['auth:sanctum', 'business.active', 'subscription.active', 'module:sales,expenses'])->group(function () {
    Route::get('/expense-categories', [ExpenseCategoryController::class, 'index']);
});

Route::middleware(['auth:sanctum', 'business.active', 'subscription.active', 'module:expenses'])->group(function () {
    Route::post('/expense-categories', [ExpenseCategoryController::class, 'store']);
    Route::get('/expense-categories/{expense_category}', [ExpenseCategoryController::class, 'show'])->whereNumber('expense_category');
    Route::put('/expense-categories/{expense_category}', [ExpenseCategoryController::class, 'update'])->whereNumber('expense_category');
    Route::patch('/expense-categories/{expense_category}', [ExpenseCategoryController::class, 'update'])->whereNumber('expense_category');
    Route::delete('/expense-categories/{expense_category}', [ExpenseCategoryController::class, 'destroy'])->whereNumber('expense_category');
});
