<?php

use App\Http\Controllers\Api\Billing\PersonalSubscriptionController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth:sanctum')->prefix('personal/subscriptions')->group(function () {
    Route::get('available', [PersonalSubscriptionController::class, 'availableModules']);
    Route::get('mine', [PersonalSubscriptionController::class, 'mySubscriptions']);
    Route::post('subscribe', [PersonalSubscriptionController::class, 'subscribe']);
    Route::post('pay', [PersonalSubscriptionController::class, 'initiatePayment']);
    Route::post('{id}/cancel', [PersonalSubscriptionController::class, 'cancel']);
});
