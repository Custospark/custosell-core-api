<?php

use App\Http\Controllers\Api\PipelineController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:sanctum', 'business.active', 'pipeline.access'])->group(function () {
    Route::get('/pipeline/boards', [PipelineController::class, 'boards']);
    Route::get('/pipeline/team-members', [PipelineController::class, 'teamMembers']);
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
    Route::patch('/pipeline/activities/{id}', [PipelineController::class, 'updateActivity'])->whereNumber('id');

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
    Route::delete('/pipeline/polls/{pollId}/vote', [PipelineController::class, 'removePollVote'])->whereNumber('pollId');
    Route::delete('/pipeline/polls/{pollId}', [PipelineController::class, 'destroyPoll'])->whereNumber('pollId');
    Route::get('/pipeline/leads/{leadId}/reminders', [PipelineController::class, 'leadReminders'])->whereNumber('leadId');
    Route::post('/pipeline/leads/{leadId}/reminders', [PipelineController::class, 'storeLeadReminder'])->whereNumber('leadId');
    Route::delete('/pipeline/reminders/{id}', [PipelineController::class, 'destroyReminder'])->whereNumber('id');

    Route::get('/pipeline/boards/{boardId}/resources/summary', [PipelineController::class, 'boardResourcesSummary'])->whereNumber('boardId');
    Route::get('/pipeline/boards/{boardId}/resources/members', [PipelineController::class, 'boardResourceMembers'])->whereNumber('boardId');
    Route::get('/pipeline/boards/{boardId}/resources', [PipelineController::class, 'boardResources'])->whereNumber('boardId');
    Route::post('/pipeline/boards/{boardId}/resources/link', [PipelineController::class, 'storeBoardLinkResource'])->whereNumber('boardId');
    Route::post('/pipeline/boards/{boardId}/resources/upload', [PipelineController::class, 'uploadBoardResource'])->whereNumber('boardId');
    Route::patch('/pipeline/resources/{id}', [PipelineController::class, 'updateBoardResource'])->whereNumber('id');
    Route::delete('/pipeline/resources/{id}', [PipelineController::class, 'destroyBoardResource'])->whereNumber('id');
    Route::post('/pipeline/resources/{id}/view', [PipelineController::class, 'recordBoardResourceView'])->whereNumber('id');
    Route::post('/pipeline/resources/{id}/download', [PipelineController::class, 'recordBoardResourceDownload'])->whereNumber('id');

    Route::get('/pipeline/boards/{boardId}/conversation/summary', [PipelineController::class, 'boardConversationSummary'])->whereNumber('boardId');
    Route::get('/pipeline/boards/{boardId}/conversation/messages', [PipelineController::class, 'boardConversationMessages'])->whereNumber('boardId');
    Route::post('/pipeline/boards/{boardId}/conversation/messages', [PipelineController::class, 'storeBoardConversationMessage'])->whereNumber('boardId');
    Route::post('/pipeline/boards/{boardId}/conversation/read', [PipelineController::class, 'markBoardConversationRead'])->whereNumber('boardId');
    Route::patch('/pipeline/conversation/messages/{id}', [PipelineController::class, 'updateBoardConversationMessage'])->whereNumber('id');
    Route::delete('/pipeline/conversation/messages/{id}', [PipelineController::class, 'destroyBoardConversationMessage'])->whereNumber('id');
    Route::post('/pipeline/conversation/messages/{id}/reaction', [PipelineController::class, 'toggleBoardConversationReaction'])->whereNumber('id');
    Route::post('/pipeline/conversation/messages/{id}/pin', [PipelineController::class, 'toggleBoardConversationPin'])->whereNumber('id');
    Route::post('/pipeline/conversation/messages/{id}/attachments', [PipelineController::class, 'uploadBoardConversationAttachment'])->whereNumber('id');
    Route::delete('/pipeline/conversation/attachments/{id}', [PipelineController::class, 'destroyBoardConversationAttachment'])->whereNumber('id');
    Route::get('/pipeline/boards/{boardId}/conversation/activity', [PipelineController::class, 'boardConversationActivity'])->whereNumber('boardId');
    Route::get('/pipeline/boards/{boardId}/automations', [PipelineController::class, 'boardAutomations'])->whereNumber('boardId');
    Route::put('/pipeline/boards/{boardId}/automations', [PipelineController::class, 'syncBoardAutomations'])->whereNumber('boardId');
    Route::post('/pipeline/boards/{boardId}/automations', [PipelineController::class, 'storeBoardAutomation'])->whereNumber('boardId');
    Route::delete('/pipeline/automations/{id}', [PipelineController::class, 'destroyBoardAutomation'])->whereNumber('id');
    Route::get('/pipeline/board-templates', [PipelineController::class, 'boardTemplates']);
    Route::post('/pipeline/board-templates', [PipelineController::class, 'storeBoardTemplate']);
    Route::post('/pipeline/boards/{boardId}/apply-template', [PipelineController::class, 'applyBoardTemplate'])->whereNumber('boardId');
});
