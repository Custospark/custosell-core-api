<?php

use App\Http\Controllers\Api\Forecasting\ForecastingController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:sanctum', 'business.active', 'module:forecasting'])->prefix('forecasting')->group(function () {
    Route::get('/overview', [ForecastingController::class, 'overview']);
    Route::get('/cash-forecast', [ForecastingController::class, 'cashForecast']);
    Route::get('/budget-vs-actual', [ForecastingController::class, 'budgetVsActual']);
    Route::get('/kpis', [ForecastingController::class, 'kpis']);

    Route::get('/budgets', [ForecastingController::class, 'indexBudgets']);
    Route::post('/budgets', [ForecastingController::class, 'storeBudget']);
    Route::get('/budgets/{id}', [ForecastingController::class, 'showBudget'])->whereNumber('id');
    Route::patch('/budgets/{id}', [ForecastingController::class, 'updateBudget'])->whereNumber('id');
    Route::delete('/budgets/{id}', [ForecastingController::class, 'destroyBudget'])->whereNumber('id');

    Route::post('/budgets/{id}/lines', [ForecastingController::class, 'storeLine'])->whereNumber('id');
    Route::patch('/budgets/{id}/lines/{lineId}', [ForecastingController::class, 'updateLine'])->whereNumber(['id', 'lineId']);
    Route::delete('/budgets/{id}/lines/{lineId}', [ForecastingController::class, 'destroyLine'])->whereNumber(['id', 'lineId']);
    Route::post('/budgets/{id}/lines/{lineId}/justify', [ForecastingController::class, 'justifyLine'])->whereNumber(['id', 'lineId']);
    Route::post('/budgets/{id}/lines/{lineId}/approve', [ForecastingController::class, 'approveLine'])->whereNumber(['id', 'lineId']);
    Route::post('/budgets/{id}/roll', [ForecastingController::class, 'rollBudget'])->whereNumber('id');

    Route::get('/snapshots', [ForecastingController::class, 'indexSnapshots']);

    Route::get('/scenarios', [ForecastingController::class, 'indexScenarios']);
    Route::post('/scenarios', [ForecastingController::class, 'storeScenario']);
    Route::get('/scenarios/{id}', [ForecastingController::class, 'showScenario'])->whereNumber('id');
    Route::patch('/scenarios/{id}', [ForecastingController::class, 'updateScenario'])->whereNumber('id');
    Route::delete('/scenarios/{id}', [ForecastingController::class, 'destroyScenario'])->whereNumber('id');
    Route::post('/scenarios/{id}/run', [ForecastingController::class, 'runScenario'])->whereNumber('id');
});
