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

        Route::put('/{budget}/lines', [PersonalBudgetController::class, 'syncLines'])->whereNumber('budget');
        Route::post('/{budget}/lines/{line}/purchase', [PersonalBudgetController::class, 'purchaseLine'])->whereNumber('budget')->whereNumber('line');
        Route::get('/{budget}/affordability', [PersonalBudgetController::class, 'affordability'])->whereNumber('budget');
    });

Route::middleware(['auth:sanctum', 'business.active', 'subscription.active', 'module:expenses'])->prefix('money')
    ->group(function () {
        Route::get('/alerts', [PersonalBudgetController::class, 'alerts']);
        Route::get('/summary', [PersonalBudgetController::class, 'moneySummary']);
    });

Route::middleware(['auth:sanctum', 'business.active', 'subscription.active', 'module:expenses'])->prefix('money')
    ->group(function () {
        Route::get('/summary', [PersonalBudgetController::class, 'moneySummary']);
    });