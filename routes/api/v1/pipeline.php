<?php

use App\Http\Controllers\Api\PipelineController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:sanctum', 'business.active', 'module:pipeline'])->group(function () {
    Route::get('/pipeline/boards', [PipelineController::class, 'boards']);
    Route::post('/pipeline/boards', [PipelineController::class, 'storeBoard']);
    Route::get('/pipeline/boards/{id}', [PipelineController::class, 'showBoard'])->whereNumber('id');
    Route::patch('/pipeline/boards/{id}', [PipelineController::class, 'updateBoard'])->whereNumber('id');
    Route::get('/pipeline/boards/{id}/kanban', [PipelineController::class, 'kanban'])->whereNumber('id');
    Route::get('/pipeline/boards/{id}/calendar', [PipelineController::class, 'calendar'])->whereNumber('id');

    Route::post('/pipeline/boards/{boardId}/stages', [PipelineController::class, 'storeStage'])->whereNumber('boardId');
    Route::post('/pipeline/boards/{boardId}/stages/reorder', [PipelineController::class, 'reorderStages'])->whereNumber('boardId');
    Route::patch('/pipeline/stages/{stageId}', [PipelineController::class, 'updateStage'])->whereNumber('stageId');
    Route::delete('/pipeline/stages/{stageId}', [PipelineController::class, 'destroyStage'])->whereNumber('stageId');

    Route::get('/pipeline/leads', [PipelineController::class, 'leads']);
    Route::post('/pipeline/leads', [PipelineController::class, 'storeLead']);
    Route::get('/pipeline/leads/{id}', [PipelineController::class, 'showLead'])->whereNumber('id');
    Route::patch('/pipeline/leads/{id}', [PipelineController::class, 'updateLead'])->whereNumber('id');
    Route::delete('/pipeline/leads/{id}', [PipelineController::class, 'destroyLead'])->whereNumber('id');
    Route::patch('/pipeline/leads/{id}/stage', [PipelineController::class, 'moveLead'])->whereNumber('id');
    Route::post('/pipeline/leads/{id}/convert', [PipelineController::class, 'convertLead'])->whereNumber('id');
    Route::post('/pipeline/leads/{leadId}/activities', [PipelineController::class, 'storeActivity'])->whereNumber('leadId');

    Route::get('/pipeline/sources', [PipelineController::class, 'sources']);
    Route::post('/pipeline/sources', [PipelineController::class, 'storeSource']);
    Route::patch('/pipeline/sources/{id}', [PipelineController::class, 'updateSource'])->whereNumber('id');
    Route::delete('/pipeline/sources/{id}', [PipelineController::class, 'destroySource'])->whereNumber('id');
    Route::get('/pipeline/insights', [PipelineController::class, 'insights']);
});
