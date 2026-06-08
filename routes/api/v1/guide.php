<?php

use App\Http\Controllers\Api\GuideFaqController;
use App\Http\Controllers\Api\GuideFeedbackController;
use App\Http\Controllers\Api\GuideTutorialController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:sanctum', 'business.active'])->prefix('guide')->group(function () {
    Route::get('/tutorials', [GuideTutorialController::class, 'index']);
    Route::get('/faqs', [GuideFaqController::class, 'index']);

    Route::get('/feedback/mine', [GuideFeedbackController::class, 'mine']);
    Route::post('/feedback', [GuideFeedbackController::class, 'store']);
});
