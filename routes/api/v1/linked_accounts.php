<?php

use App\Http\Controllers\Api\LinkedAccountController;
use Illuminate\Support\Facades\Route;

// Auth-only: a user must be able to switch accounts even if their current
// business is suspended/restricted. The link and unlink initiate endpoints are
// throttled because they validate credentials / send security codes.
Route::middleware(['auth:sanctum'])->group(function () {
    Route::get('/linked-accounts', [LinkedAccountController::class, 'index']);
    Route::post('/linked-accounts', [LinkedAccountController::class, 'initiateLink'])->middleware('throttle:10,1');
    Route::post('/linked-accounts/confirm', [LinkedAccountController::class, 'confirmLink']);
    Route::post('/linked-accounts/{linked_user_id}/switch', [LinkedAccountController::class, 'switchTo'])->whereNumber('linked_user_id');
    Route::post('/linked-accounts/{linked_user_id}/set-primary', [LinkedAccountController::class, 'setPrimary'])->whereNumber('linked_user_id');
    Route::post('/linked-accounts/{linked_user_id}/unlink', [LinkedAccountController::class, 'initiateUnlink'])->whereNumber('linked_user_id')->middleware('throttle:10,1');
    Route::post('/linked-accounts/{linked_user_id}/unlink/confirm', [LinkedAccountController::class, 'confirmUnlink'])->whereNumber('linked_user_id');
});
