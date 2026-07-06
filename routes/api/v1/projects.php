<?php

use App\Http\Controllers\Api\ProjectController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:sanctum', 'business.active', 'module:estimates'])->group(function () {
    Route::get('/projects', [ProjectController::class, 'index']);
    Route::post('/projects', [ProjectController::class, 'store']);
    Route::get('/projects/{id}', [ProjectController::class, 'show'])->whereNumber('id');
    Route::put('/projects/{id}', [ProjectController::class, 'update'])->whereNumber('id');
    Route::patch('/projects/{id}', [ProjectController::class, 'update'])->whereNumber('id');
    Route::delete('/projects/{id}', [ProjectController::class, 'destroy'])->whereNumber('id');
    Route::get('/projects/{id}/budget-summary', [ProjectController::class, 'budgetSummary'])->whereNumber('id');
    Route::get('/projects/{id}/profitability', [ProjectController::class, 'profitability'])->whereNumber('id');

    Route::post('/projects/{projectId}/tasks', [ProjectController::class, 'storeTask'])->whereNumber('projectId');
    Route::patch('/project-tasks/{taskId}', [ProjectController::class, 'updateTask'])->whereNumber('taskId');
    Route::delete('/project-tasks/{taskId}', [ProjectController::class, 'destroyTask'])->whereNumber('taskId');

    Route::post('/projects/{projectId}/timesheets', [ProjectController::class, 'storeTimesheet'])->whereNumber('projectId');
    Route::patch('/timesheet-entries/{entryId}', [ProjectController::class, 'updateTimesheet'])->whereNumber('entryId');
    Route::delete('/timesheet-entries/{entryId}', [ProjectController::class, 'destroyTimesheet'])->whereNumber('entryId');

    Route::post('/projects/{projectId}/allocations', [ProjectController::class, 'storeAllocation'])->whereNumber('projectId');
    Route::patch('/project-allocations/{allocationId}', [ProjectController::class, 'updateAllocation'])->whereNumber('allocationId');
    Route::delete('/project-allocations/{allocationId}', [ProjectController::class, 'destroyAllocation'])->whereNumber('allocationId');
});
