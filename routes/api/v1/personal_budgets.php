<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\PersonalBudgetController;

Route::middleware(['auth:sanctum', 'business.active', 'subscription.active', 'module:expenses'])->prefix('budgets')
    ->group(function () {
        Route::get('/', [PersonalBudgetController::class, 'index']);
        Route::post('/', [PersonalBudgetController::class, 'store']);
        Route::get('/{budget}', [PersonalBudgetController::class, 'show'])->whereNumber('budget');
        Route::put('/{budget}', [PersonalBudgetController::class, 'update'])->whereNumber('budget');
        Route::patch('/{budget}', [PersonalBudgetController::class, 'update'])->whereNumber('budget');
        Route::delete('/{budget}', [PersonalBudgetController::class, 'destroy'])->whereNumber('budget');
    });