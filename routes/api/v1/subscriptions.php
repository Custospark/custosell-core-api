<?php

use App\Http\Controllers\Api\SubscriptionController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:sanctum', 'business.active'])->group(function () {
    Route::get('subscriptions/current', [SubscriptionController::class, 'current'])->name('subscriptions.current');
    Route::post('subscriptions/subscribe', [SubscriptionController::class, 'subscribe'])->name('subscriptions.subscribe');
    Route::post('subscriptions/{id}/cancel', [SubscriptionController::class, 'cancelPlan'])->name('subscriptions.cancel');
    Route::post('subscriptions/{id}/reactivate', [SubscriptionController::class, 'reactivate'])->name('subscriptions.reactivate');
    Route::post('subscriptions/{id}/upgrade', [SubscriptionController::class, 'upgrade'])->name('subscriptions.upgrade');
    Route::post('subscriptions/{id}/downgrade', [SubscriptionController::class, 'downgrade'])->name('subscriptions.downgrade');
    Route::get('subscriptions/{id}/changes', [SubscriptionController::class, 'changes'])->name('subscriptions.changes');
    Route::get('subscriptions/access', [SubscriptionController::class, 'checkAccess'])->name('subscriptions.access');

    Route::apiResource('subscriptions', SubscriptionController::class);
});
