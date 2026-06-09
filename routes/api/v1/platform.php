<?php

use App\Http\Controllers\Api\Platform\PlatformBusinessController;
use App\Http\Controllers\Api\Platform\PlatformGuideFaqController;
use App\Http\Controllers\Api\Platform\PlatformGuideFeedbackController;
use App\Http\Controllers\Api\Platform\PlatformGuideTutorialController;
use App\Http\Controllers\Api\Platform\PlatformNotificationDispatchController;
use App\Http\Controllers\Api\Platform\PlatformOverviewController;
use App\Http\Controllers\Api\Platform\PlatformRoleController;
use App\Http\Controllers\Api\Platform\PlatformUserController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:sanctum', 'business.active'])->prefix('platform')->group(function () {
    Route::middleware(['platform:platform.overview.view'])->group(function () {
        Route::get('/overview', [PlatformOverviewController::class, 'summary']);
        Route::get('/metrics', [PlatformOverviewController::class, 'metrics']);
        Route::get('/notification-dispatches', [PlatformNotificationDispatchController::class, 'index']);
        Route::post('/notification-dispatches/bulk-delete', [PlatformNotificationDispatchController::class, 'bulkDestroy']);
        Route::get('/notification-dispatches/{id}', [PlatformNotificationDispatchController::class, 'show']);
        Route::delete('/notification-dispatches/{id}', [PlatformNotificationDispatchController::class, 'destroy']);
    });

    Route::middleware(['platform:platform.businesses.view'])->group(function () {
        Route::get('/businesses/stats', [PlatformBusinessController::class, 'stats']);
        Route::get('/businesses', [PlatformBusinessController::class, 'index']);
    });

    Route::middleware(['platform:platform.businesses.manage'])->group(function () {
        Route::post('/businesses/bulk-delete', [PlatformBusinessController::class, 'bulkDelete']);
        Route::post('/businesses/bulk-status', [PlatformBusinessController::class, 'bulkUpdateStatus']);
        Route::post('/businesses/notify', [PlatformBusinessController::class, 'notify']);
        Route::patch('/businesses/{id}/status', [PlatformBusinessController::class, 'updateStatus']);
        Route::delete('/businesses/{id}', [PlatformBusinessController::class, 'destroy']);
    });

    Route::middleware(['platform:platform.users.view'])->group(function () {
        Route::get('/users', [PlatformUserController::class, 'index']);
    });

    Route::middleware(['platform:platform.users.manage'])->group(function () {
        Route::patch('/users/{id}/status', [PlatformUserController::class, 'updateStatus']);
        Route::delete('/users/{id}', [PlatformUserController::class, 'destroy']);
        Route::post('/users/bulk-delete', [PlatformUserController::class, 'bulkDelete']);
        Route::post('/users/notify', [PlatformUserController::class, 'notify']);
    });

    Route::middleware(['platform:platform.roles.view'])->group(function () {
        Route::get('/roles', [PlatformRoleController::class, 'index']);
        Route::get('/permissions', [PlatformRoleController::class, 'permissions']);
    });

    Route::middleware(['platform:platform.roles.manage'])->group(function () {
        Route::post('/roles', [PlatformRoleController::class, 'store']);
        Route::put('/roles/{id}', [PlatformRoleController::class, 'update']);
        Route::delete('/roles/{id}', [PlatformRoleController::class, 'destroy']);
        Route::post('/users/{id}/roles', [PlatformUserController::class, 'assignRole']);
        Route::delete('/users/{id}/roles/{role}', [PlatformUserController::class, 'revokeRole']);
        Route::post('/users/bulk-assign-roles', [PlatformUserController::class, 'bulkAssignRoles']);
    });

    Route::middleware(['platform:platform.guide.view'])->prefix('guide')->group(function () {
        Route::get('/tutorials', [PlatformGuideTutorialController::class, 'index']);
        Route::get('/faqs', [PlatformGuideFaqController::class, 'index']);
        Route::get('/feedback', [PlatformGuideFeedbackController::class, 'index']);
        Route::get('/feedback/{guideFeedback}', [PlatformGuideFeedbackController::class, 'show']);
    });

    Route::middleware(['platform:platform.guide.manage'])->prefix('guide')->group(function () {
        Route::post('/tutorials/preview-thumbnail', [PlatformGuideTutorialController::class, 'previewThumbnail']);
        Route::post('/tutorials/upload-thumbnail-pending', [PlatformGuideTutorialController::class, 'uploadThumbnailPending']);
        Route::post('/tutorials/{guideTutorial}/upload-thumbnail', [PlatformGuideTutorialController::class, 'uploadThumbnailForMaterial']);
        Route::post('/tutorials', [PlatformGuideTutorialController::class, 'store']);
        Route::put('/tutorials/{guideTutorial}', [PlatformGuideTutorialController::class, 'update']);
        Route::delete('/tutorials/{guideTutorial}', [PlatformGuideTutorialController::class, 'destroy']);

        Route::post('/faqs', [PlatformGuideFaqController::class, 'store']);
        Route::put('/faqs/{guideFaq}', [PlatformGuideFaqController::class, 'update']);
        Route::delete('/faqs/{guideFaq}', [PlatformGuideFaqController::class, 'destroy']);
    });

    Route::middleware(['platform:platform.guide.feedback.manage'])->prefix('guide')->group(function () {
        Route::patch('/feedback/{guideFeedback}', [PlatformGuideFeedbackController::class, 'update']);
        Route::delete('/feedback/{guideFeedback}', [PlatformGuideFeedbackController::class, 'destroy']);
        Route::post('/feedback/bulk-delete', [PlatformGuideFeedbackController::class, 'bulkDestroy']);
    });
});
