<?php

use App\Http\Controllers\Api\Pipeline\PipelineActivityController;
use App\Http\Controllers\Api\Pipeline\PipelineAutomationController;
use App\Http\Controllers\Api\Pipeline\PipelineBoardController;
use App\Http\Controllers\Api\Pipeline\PipelineBookingController;
use App\Http\Controllers\Api\Pipeline\PipelineCalendarController;
use App\Http\Controllers\Api\Pipeline\PipelineChecklistController;
use App\Http\Controllers\Api\Pipeline\PipelineCollaborationController;
use App\Http\Controllers\Api\Pipeline\PipelineConversationController;
use App\Http\Controllers\Api\Pipeline\PipelineLabelController;
use App\Http\Controllers\Api\Pipeline\PipelineLeadController;
use App\Http\Controllers\Api\Pipeline\PipelineMetadataController;
use App\Http\Controllers\Api\Pipeline\PipelineProgressController;
use App\Http\Controllers\Api\Pipeline\PipelineResourceController;
use App\Http\Controllers\Api\Pipeline\PipelineSourceController;
use App\Http\Controllers\Api\Pipeline\PipelineStageController;
use App\Http\Controllers\Api\Pipeline\PipelineTemplateController;
use App\Http\Controllers\Api\WallOfFameController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:sanctum', 'business.active', 'subscription.active', 'pipeline.access'])->group(function () {
    Route::get('/pipeline/boards', [PipelineBoardController::class, 'boards']);
    Route::get('/pipeline/team-members', [PipelineBoardController::class, 'teamMembers']);
    Route::post('/pipeline/boards', [PipelineBoardController::class, 'storeBoard']);
    Route::get('/pipeline/boards/{boardRef}', [PipelineBoardController::class, 'showBoard']);
    Route::patch('/pipeline/boards/{boardRef}', [PipelineBoardController::class, 'updateBoard']);
    Route::delete('/pipeline/boards/{boardRef}', [PipelineBoardController::class, 'destroyBoard']);
    Route::post('/pipeline/boards/{boardRef}/duplicate', [PipelineBoardController::class, 'duplicateBoard']);
    Route::get('/pipeline/boards/{boardRef}/kanban', [PipelineBoardController::class, 'kanban']);
    Route::get('/pipeline/boards/{boardRef}/calendar', [PipelineCalendarController::class, 'calendar']);
    Route::get('/pipeline/calendar', [PipelineCalendarController::class, 'allBoardsCalendar']);
    Route::post('/pipeline/boards/{boardRef}/background', [PipelineBoardController::class, 'uploadBoardBackground']);
    Route::get('/pipeline/boards/{boardRef}/import-template', [PipelineBoardController::class, 'downloadLeadImportTemplate']);
    Route::post('/pipeline/boards/{boardRef}/import', [PipelineBoardController::class, 'importLeads']);

    Route::post('/pipeline/boards/{boardId}/stages', [PipelineStageController::class, 'storeStage'])->whereNumber('boardId');
    Route::post('/pipeline/boards/{boardId}/stages/reorder', [PipelineStageController::class, 'reorderStages'])->whereNumber('boardId');
    Route::patch('/pipeline/stages/{stageId}', [PipelineStageController::class, 'updateStage'])->whereNumber('stageId');
    Route::delete('/pipeline/stages/{stageId}', [PipelineStageController::class, 'destroyStage'])->whereNumber('stageId');

    Route::get('/pipeline/leads', [PipelineLeadController::class, 'leads']);
    Route::post('/pipeline/leads', [PipelineLeadController::class, 'storeLead']);
    Route::get('/pipeline/leads/{id}', [PipelineLeadController::class, 'showLead'])->whereNumber('id');
    Route::patch('/pipeline/leads/{id}', [PipelineLeadController::class, 'updateLead'])->whereNumber('id');
    Route::delete('/pipeline/leads/{id}', [PipelineLeadController::class, 'destroyLead'])->whereNumber('id');
    Route::patch('/pipeline/leads/{id}/stage', [PipelineLeadController::class, 'moveLead'])->whereNumber('id');
    Route::post('/pipeline/leads/{id}/convert', [PipelineLeadController::class, 'convertLead'])->whereNumber('id');
    Route::post('/pipeline/leads/{leadId}/activities', [PipelineActivityController::class, 'storeActivity'])->whereNumber('leadId');
    Route::delete('/pipeline/activities/{id}', [PipelineActivityController::class, 'destroyActivity'])->whereNumber('id');
    Route::patch('/pipeline/activities/{id}', [PipelineActivityController::class, 'updateActivity'])->whereNumber('id');

    Route::get('/pipeline/sources', [PipelineSourceController::class, 'sources']);
    Route::post('/pipeline/sources', [PipelineSourceController::class, 'storeSource']);
    Route::patch('/pipeline/sources/{id}', [PipelineSourceController::class, 'updateSource'])->whereNumber('id');
    Route::delete('/pipeline/sources/{id}', [PipelineSourceController::class, 'destroySource'])->whereNumber('id');
    Route::get('/pipeline/insights', [PipelineCalendarController::class, 'insights']);

    Route::get('/pipeline/labels', [PipelineLabelController::class, 'labels']);
    Route::post('/pipeline/labels', [PipelineLabelController::class, 'storeLabel']);
    Route::patch('/pipeline/labels/{id}', [PipelineLabelController::class, 'updateLabel'])->whereNumber('id');
    Route::delete('/pipeline/labels/{id}', [PipelineLabelController::class, 'destroyLabel'])->whereNumber('id');

    Route::post('/pipeline/leads/{leadId}/checklists', [PipelineChecklistController::class, 'storeChecklist'])->whereNumber('leadId');
    Route::patch('/pipeline/checklists/{id}', [PipelineChecklistController::class, 'updateChecklist'])->whereNumber('id');
    Route::delete('/pipeline/checklists/{id}', [PipelineChecklistController::class, 'destroyChecklist'])->whereNumber('id');
    Route::post('/pipeline/checklists/{checklistId}/items', [PipelineChecklistController::class, 'storeChecklistItem'])->whereNumber('checklistId');
    Route::patch('/pipeline/checklist-items/{id}', [PipelineChecklistController::class, 'updateChecklistItem'])->whereNumber('id');
    Route::delete('/pipeline/checklist-items/{id}', [PipelineChecklistController::class, 'destroyChecklistItem'])->whereNumber('id');

    Route::post('/pipeline/leads/{leadId}/attachments', [App\Http\Controllers\Api\PipelineAttachmentController::class, 'store'])->whereNumber('leadId');
    Route::post('/pipeline/leads/{leadId}/attachments/link', [App\Http\Controllers\Api\PipelineAttachmentController::class, 'storeLink'])->whereNumber('leadId');
    Route::delete('/pipeline/attachments/{id}', [App\Http\Controllers\Api\PipelineAttachmentController::class, 'destroy'])->whereNumber('id');

    Route::post('/pipeline/activities/{id}/reaction', [PipelineActivityController::class, 'toggleActivityReaction'])->whereNumber('id');
    Route::get('/pipeline/boards/{boardId}/collaboration-summary', [PipelineCollaborationController::class, 'boardCollaborationSummary'])->whereNumber('boardId');
    Route::get('/pipeline/boards/{boardId}/announcements', [PipelineCollaborationController::class, 'boardAnnouncements'])->whereNumber('boardId');
    Route::post('/pipeline/boards/{boardId}/announcements', [PipelineCollaborationController::class, 'storeBoardAnnouncement'])->whereNumber('boardId');
    Route::patch('/pipeline/announcements/{id}/read', [PipelineCollaborationController::class, 'setAnnouncementRead'])->whereNumber('id');
    Route::delete('/pipeline/announcements/{id}', [PipelineCollaborationController::class, 'destroyBoardAnnouncement'])->whereNumber('id');
    Route::get('/pipeline/boards/{boardId}/polls', [PipelineCollaborationController::class, 'boardPolls'])->whereNumber('boardId');
    Route::post('/pipeline/boards/{boardId}/polls', [PipelineCollaborationController::class, 'storeBoardPoll'])->whereNumber('boardId');
    Route::patch('/pipeline/polls/{pollId}', [PipelineCollaborationController::class, 'updatePoll'])->whereNumber('pollId');
    Route::post('/pipeline/polls/{pollId}/vote', [PipelineCollaborationController::class, 'votePoll'])->whereNumber('pollId');
    Route::delete('/pipeline/polls/{pollId}/vote', [PipelineCollaborationController::class, 'removePollVote'])->whereNumber('pollId');
    Route::delete('/pipeline/polls/{pollId}', [PipelineCollaborationController::class, 'destroyPoll'])->whereNumber('pollId');
    Route::get('/pipeline/leads/{leadId}/reminders', [PipelineCollaborationController::class, 'leadReminders'])->whereNumber('leadId');
    Route::post('/pipeline/leads/{leadId}/reminders', [PipelineCollaborationController::class, 'storeLeadReminder'])->whereNumber('leadId');
    Route::delete('/pipeline/reminders/{id}', [PipelineCollaborationController::class, 'destroyReminder'])->whereNumber('id');

    Route::get('/pipeline/boards/{boardId}/resources/summary', [PipelineResourceController::class, 'boardResourcesSummary'])->whereNumber('boardId');
    Route::get('/pipeline/boards/{boardId}/resources/members', [PipelineResourceController::class, 'boardResourceMembers'])->whereNumber('boardId');
    Route::get('/pipeline/boards/{boardId}/resources', [PipelineResourceController::class, 'boardResources'])->whereNumber('boardId');
    Route::post('/pipeline/boards/{boardId}/resources/link', [PipelineResourceController::class, 'storeBoardLinkResource'])->whereNumber('boardId');
    Route::post('/pipeline/boards/{boardId}/resources/upload', [PipelineResourceController::class, 'uploadBoardResource'])->whereNumber('boardId');
    Route::patch('/pipeline/resources/{id}', [PipelineResourceController::class, 'updateBoardResource'])->whereNumber('id');
    Route::delete('/pipeline/resources/{id}', [PipelineResourceController::class, 'destroyBoardResource'])->whereNumber('id');
    Route::post('/pipeline/resources/{id}/view', [PipelineResourceController::class, 'recordBoardResourceView'])->whereNumber('id');
    Route::post('/pipeline/resources/{id}/download', [PipelineResourceController::class, 'recordBoardResourceDownload'])->whereNumber('id');

    Route::get('/pipeline/boards/{boardId}/conversation/summary', [PipelineConversationController::class, 'boardConversationSummary'])->whereNumber('boardId');
    Route::get('/pipeline/boards/{boardId}/conversation/messages', [PipelineConversationController::class, 'boardConversationMessages'])->whereNumber('boardId');
    Route::post('/pipeline/boards/{boardId}/conversation/messages', [PipelineConversationController::class, 'storeBoardConversationMessage'])->whereNumber('boardId');
    Route::post('/pipeline/boards/{boardId}/conversation/read', [PipelineConversationController::class, 'markBoardConversationRead'])->whereNumber('boardId');
    Route::patch('/pipeline/conversation/messages/{id}', [PipelineConversationController::class, 'updateBoardConversationMessage'])->whereNumber('id');
    Route::delete('/pipeline/conversation/messages/{id}', [PipelineConversationController::class, 'destroyBoardConversationMessage'])->whereNumber('id');
    Route::post('/pipeline/conversation/messages/{id}/reaction', [PipelineConversationController::class, 'toggleBoardConversationReaction'])->whereNumber('id');
    Route::post('/pipeline/conversation/messages/{id}/pin', [PipelineConversationController::class, 'toggleBoardConversationPin'])->whereNumber('id');
    Route::post('/pipeline/conversation/messages/{id}/attachments', [PipelineConversationController::class, 'uploadBoardConversationAttachment'])->whereNumber('id');
    Route::delete('/pipeline/conversation/attachments/{id}', [PipelineConversationController::class, 'destroyBoardConversationAttachment'])->whereNumber('id');
    Route::get('/pipeline/boards/{boardId}/conversation/activity', [PipelineConversationController::class, 'boardConversationActivity'])->whereNumber('boardId');

    Route::get('/pipeline/boards/{boardId}/automations', [PipelineAutomationController::class, 'boardAutomations'])->whereNumber('boardId');
    Route::put('/pipeline/boards/{boardId}/automations', [PipelineAutomationController::class, 'syncBoardAutomations'])->whereNumber('boardId');
    Route::post('/pipeline/boards/{boardId}/automations', [PipelineAutomationController::class, 'storeBoardAutomation'])->whereNumber('boardId');
    Route::delete('/pipeline/automations/{id}', [PipelineAutomationController::class, 'destroyBoardAutomation'])->whereNumber('id');

    Route::get('/pipeline/board-templates', [PipelineTemplateController::class, 'boardTemplates']);
    Route::post('/pipeline/board-templates', [PipelineTemplateController::class, 'storeBoardTemplate']);
    Route::post('/pipeline/boards/{boardId}/apply-template', [PipelineTemplateController::class, 'applyBoardTemplate'])->whereNumber('boardId');

    Route::get('/pipeline/boards/{boardId}/progress/summary', [PipelineProgressController::class, 'boardProgressSummary'])->whereNumber('boardId');
    Route::get('/pipeline/boards/{boardId}/progress/query', [PipelineProgressController::class, 'boardProgressQuery'])->whereNumber('boardId');
    Route::get('/pipeline/boards/{boardId}/progress/my', [PipelineProgressController::class, 'myBoardProgress'])->whereNumber('boardId');
    Route::get('/pipeline/boards/{boardId}/progress/config', [PipelineProgressController::class, 'boardProgressConfig'])->whereNumber('boardId');
    Route::put('/pipeline/boards/{boardId}/progress/config', [PipelineProgressController::class, 'updateBoardProgressConfig'])->whereNumber('boardId');
    Route::post('/pipeline/boards/{boardId}/targets/decompose-preview', [PipelineProgressController::class, 'decomposeBoardTargetPreview'])->whereNumber('boardId');
    Route::get('/pipeline/boards/{boardId}/progress/export', [PipelineProgressController::class, 'exportBoardProgress'])->whereNumber('boardId');
    Route::get('/pipeline/boards/{boardId}/targets', [PipelineProgressController::class, 'boardTargets'])->whereNumber('boardId');
    Route::post('/pipeline/boards/{boardId}/targets', [PipelineProgressController::class, 'storeBoardTarget'])->whereNumber('boardId');
    Route::patch('/pipeline/targets/{targetId}', [PipelineProgressController::class, 'updateBoardTarget'])->whereNumber('targetId');
    Route::delete('/pipeline/targets/{targetId}', [PipelineProgressController::class, 'destroyBoardTarget'])->whereNumber('targetId');

    Route::get('/pipeline/leads/{lead}/links', [PipelineLeadController::class, 'leadLinks'])->whereNumber('lead');
    Route::post('/pipeline/leads/{lead}/links', [PipelineLeadController::class, 'storeLeadLink'])->whereNumber('lead');
    Route::delete('/pipeline/links/{id}', [PipelineLeadController::class, 'destroyLeadLink'])->whereNumber('id');

    Route::get('/pipeline/boards/{boardId}/meta-fields', [PipelineMetadataController::class, 'boardMetaFields'])->whereNumber('boardId');
    Route::post('/pipeline/boards/{boardId}/meta-fields', [PipelineMetadataController::class, 'storeBoardMetaField'])->whereNumber('boardId');
    Route::patch('/pipeline/meta-fields/{id}', [PipelineMetadataController::class, 'updateBoardMetaField'])->whereNumber('id');
    Route::delete('/pipeline/meta-fields/{id}', [PipelineMetadataController::class, 'destroyBoardMetaField'])->whereNumber('id');
    Route::match(['get', 'post'], '/pipeline/leads/{leadId}/meta-values', [PipelineLeadController::class, 'syncLeadMetaValues'])->whereNumber('leadId');

    Route::get('/pipeline/boards/{boardId}/booking-settings', [PipelineBookingController::class, 'getBookingSettings'])->whereNumber('boardId');
    Route::put('/pipeline/boards/{boardId}/booking-settings', [PipelineBookingController::class, 'updateBookingSettings'])->whereNumber('boardId');
    Route::post('/pipeline/boards/{boardId}/booking-settings/regenerate-token', [PipelineBookingController::class, 'regenerateBookingToken'])->whereNumber('boardId');
    Route::post('/pipeline/leads/{leadId}/approve-booking', [PipelineBookingController::class, 'approveBooking'])->whereNumber('leadId');
    Route::post('/pipeline/leads/{leadId}/complete-booking', [PipelineBookingController::class, 'completeBooking'])->whereNumber('leadId');
    Route::post('/pipeline/leads/{leadId}/reject-booking', [PipelineBookingController::class, 'rejectBooking'])->whereNumber('leadId');
    Route::post('/pipeline/leads/{leadId}/clear-booking', [PipelineBookingController::class, 'clearBooking'])->whereNumber('leadId');
    Route::post('/pipeline/leads/{leadId}/schedule-meeting', [PipelineBookingController::class, 'scheduleMeeting'])->whereNumber('leadId');
    Route::patch('/pipeline/meetings/{meetingId}', [PipelineBookingController::class, 'updateMeeting'])->whereNumber('meetingId');
    Route::delete('/pipeline/meetings/{meetingId}', [PipelineBookingController::class, 'deleteMeeting'])->whereNumber('meetingId');

    Route::get('/pipeline/wall-of-fame', [WallOfFameController::class, 'index']);
    Route::post('/pipeline/wall-of-fame', [WallOfFameController::class, 'store']);
    Route::patch('/pipeline/wall-of-fame/{wallPost}', [WallOfFameController::class, 'update']);
    Route::delete('/pipeline/wall-of-fame/{wallPost}', [WallOfFameController::class, 'destroy']);
});

Route::get('/public/book/{token}', [App\Http\Controllers\Api\PublicBookingController::class, 'info']);
Route::get('/public/book/{token}/slots', [App\Http\Controllers\Api\PublicBookingController::class, 'slots']);
Route::post('/public/book/{token}', [App\Http\Controllers\Api\PublicBookingController::class, 'book']);
Route::get('/public/book/{token}/check/{reference}', [App\Http\Controllers\Api\PublicBookingController::class, 'check']);
