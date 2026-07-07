<?php

use App\Http\Controllers\Api\PipelineController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:sanctum', 'business.active', 'pipeline.access'])->group(function () {
    Route::get('/pipeline/boards', [PipelineController::class, 'boards']);
    Route::post('/pipeline/boards', [PipelineController::class, 'storeBoard']);
    Route::get('/pipeline/boards/{id}', [PipelineController::class, 'showBoard'])->whereNumber('id');
    Route::patch('/pipeline/boards/{id}', [PipelineController::class, 'updateBoard'])->whereNumber('id');
    Route::get('/pipeline/boards/{id}/kanban', [PipelineController::class, 'kanban'])->whereNumber('id');
    Route::get('/pipeline/boards/{id}/calendar', [PipelineController::class, 'calendar'])->whereNumber('id');
    Route::post('/pipeline/boards/{id}/background', [PipelineController::class, 'uploadBoardBackground'])->whereNumber('id');

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
    Route::delete('/pipeline/activities/{id}', [PipelineController::class, 'destroyActivity'])->whereNumber('id');

    Route::get('/pipeline/sources', [PipelineController::class, 'sources']);
    Route::post('/pipeline/sources', [PipelineController::class, 'storeSource']);
    Route::patch('/pipeline/sources/{id}', [PipelineController::class, 'updateSource'])->whereNumber('id');
    Route::delete('/pipeline/sources/{id}', [PipelineController::class, 'destroySource'])->whereNumber('id');
    Route::get('/pipeline/insights', [PipelineController::class, 'insights']);

    Route::get('/pipeline/labels', [PipelineController::class, 'labels']);
    Route::post('/pipeline/labels', [PipelineController::class, 'storeLabel']);
    Route::patch('/pipeline/labels/{id}', [PipelineController::class, 'updateLabel'])->whereNumber('id');
    Route::delete('/pipeline/labels/{id}', [PipelineController::class, 'destroyLabel'])->whereNumber('id');

    Route::post('/pipeline/leads/{leadId}/checklists', [PipelineController::class, 'storeChecklist'])->whereNumber('leadId');
    Route::patch('/pipeline/checklists/{id}', [PipelineController::class, 'updateChecklist'])->whereNumber('id');
    Route::delete('/pipeline/checklists/{id}', [PipelineController::class, 'destroyChecklist'])->whereNumber('id');
    Route::post('/pipeline/checklists/{checklistId}/items', [PipelineController::class, 'storeChecklistItem'])->whereNumber('checklistId');
    Route::patch('/pipeline/checklist-items/{id}', [PipelineController::class, 'updateChecklistItem'])->whereNumber('id');
    Route::delete('/pipeline/checklist-items/{id}', [PipelineController::class, 'destroyChecklistItem'])->whereNumber('id');

    Route::post('/pipeline/leads/{leadId}/attachments', [PipelineController::class, 'storeAttachment'])->whereNumber('leadId');
    Route::delete('/pipeline/attachments/{id}', [PipelineController::class, 'destroyAttachment'])->whereNumber('id');

    Route::post('/pipeline/activities/{id}/reaction', [PipelineController::class, 'toggleActivityReaction'])->whereNumber('id');
    Route::get('/pipeline/boards/{boardId}/collaboration-summary', [PipelineController::class, 'boardCollaborationSummary'])->whereNumber('boardId');
    Route::get('/pipeline/boards/{boardId}/announcements', [PipelineController::class, 'boardAnnouncements'])->whereNumber('boardId');
    Route::post('/pipeline/boards/{boardId}/announcements', [PipelineController::class, 'storeBoardAnnouncement'])->whereNumber('boardId');
    Route::patch('/pipeline/announcements/{id}/read', [PipelineController::class, 'setAnnouncementRead'])->whereNumber('id');
    Route::delete('/pipeline/announcements/{id}', [PipelineController::class, 'destroyBoardAnnouncement'])->whereNumber('id');
    Route::get('/pipeline/boards/{boardId}/polls', [PipelineController::class, 'boardPolls'])->whereNumber('boardId');
    Route::post('/pipeline/boards/{boardId}/polls', [PipelineController::class, 'storeBoardPoll'])->whereNumber('boardId');
    Route::post('/pipeline/polls/{pollId}/vote', [PipelineController::class, 'votePoll'])->whereNumber('pollId');
    Route::get('/pipeline/leads/{leadId}/reminders', [PipelineController::class, 'leadReminders'])->whereNumber('leadId');
    Route::post('/pipeline/leads/{leadId}/reminders', [PipelineController::class, 'storeLeadReminder'])->whereNumber('leadId');
    Route::delete('/pipeline/reminders/{id}', [PipelineController::class, 'destroyReminder'])->whereNumber('id');
});
