<?php

use App\Http\Controllers\Api\AssistantController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:sanctum', 'business.active'])->group(function () {
    Route::post('/assistant/chat', [AssistantController::class, 'chat'])->middleware('throttle:30,1');
});

// Guest how-to answers (landing/auth pages): tighter quota, no business data.
Route::post('/assistant/guide', [AssistantController::class, 'guide'])->middleware('throttle:10,1');
