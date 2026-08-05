<?php

use App\Http\Controllers\Api\PushSubscriptionController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:sanctum'])->group(function () {
    Route::get('/webpush/status', [PushSubscriptionController::class, 'status']);
    Route::post('/webpush/subscribe', [PushSubscriptionController::class, 'store']);
    Route::delete('/webpush/unsubscribe', [PushSubscriptionController::class, 'destroy']);
});