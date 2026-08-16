<?php

use App\Http\Controllers\Api\LinkedAccountController;
use Illuminate\Support\Facades\Route;

// Auth-only: a user must be able to switch accounts even if their current
// business is suspended/restricted. The link endpoint is throttled because it
// validates credentials (brute-force protection).
Route::middleware(['auth:sanctum'])->group(function () {
    Route::get('/linked-accounts', [LinkedAccountController::class, 'index']);
    Route::post('/linked-accounts', [LinkedAccountController::class, 'store'])->middleware('throttle:10,1');
    Route::post('/linked-accounts/{linked_user_id}/switch', [LinkedAccountController::class, 'switchTo'])->whereNumber('linked_user_id');
    Route::post('/linked-accounts/{linked_user_id}/set-primary', [LinkedAccountController::class, 'setPrimary'])->whereNumber('linked_user_id');
    Route::delete('/linked-accounts/{linked_user_id}', [LinkedAccountController::class, 'destroy'])->whereNumber('linked_user_id');
});
