<?php

use App\Http\Controllers\Api\ExpenseAttachmentController;
use App\Http\Controllers\Api\ExpenseController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:sanctum', 'business.active', 'subscription.active', 'module:expenses'])->group(function () {
    Route::get('/expenses/overview', [ExpenseController::class, 'overview']);
    Route::get('/expenses/budgets', [ExpenseController::class, 'budgets']);
    Route::get('/expenses/summary', [ExpenseController::class, 'summary']);
    Route::get('/expenses/export', [ExpenseController::class, 'export']);
    Route::delete('/expenses/{expense}', [ExpenseController::class, 'destroy'])->whereNumber('expense');
});

/**
 * Shift expense API - sales module (My Shift UI) or expenses module.
 * The Expenses nav is not shown to sales-only staff; this route group exists for My Shift.
 */
Route::middleware(['auth:sanctum', 'business.active', 'subscription.active', 'module:sales,expenses'])->group(function () {
    Route::get('/expenses/by-shift/{shiftId}', [ExpenseController::class, 'byShift'])->whereNumber('shiftId');
    Route::get('/expenses', [ExpenseController::class, 'index']);
    Route::post('/expenses', [ExpenseController::class, 'store']);
    Route::get('/expenses/{expense}', [ExpenseController::class, 'show'])->whereNumber('expense');
    Route::put('/expenses/{expense}', [ExpenseController::class, 'update'])->whereNumber('expense');
    Route::patch('/expenses/{expense}', [ExpenseController::class, 'update'])->whereNumber('expense');

    Route::post('/expenses/{expense}/attachments', [ExpenseAttachmentController::class, 'store'])->whereNumber('expense');
    Route::post('/expenses/{expense}/attachments/link', [ExpenseAttachmentController::class, 'storeLink'])->whereNumber('expense');
    Route::delete('/expense-attachments/{id}', [ExpenseAttachmentController::class, 'destroy'])->whereNumber('id');
});
