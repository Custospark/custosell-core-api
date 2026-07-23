<?php

use App\Http\Controllers\Api\Billing\GatewayWebhookController;
use App\Http\Controllers\Api\Billing\PaymentController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:sanctum', 'business.active'])->prefix('billing')->group(function () {
    Route::get('payments', [PaymentController::class, 'index'])->name('billing.payments.index');
    Route::get('payments/{id}', [PaymentController::class, 'show'])->name('billing.payments.show');
    Route::post('payments/initiate', [PaymentController::class, 'initiateGateway'])->name('billing.payments.initiate');
    Route::post('payments/{id}/confirm', [PaymentController::class, 'confirm'])->name('billing.payments.confirm');
});

Route::prefix('billing/gateway')->group(function () {
    Route::post('{gateway}/webhook', [GatewayWebhookController::class, 'webhook'])->name('billing.gateway.webhook');
    Route::get('{gateway}/callback', [GatewayWebhookController::class, 'callback'])->name('billing.gateway.callback');
    Route::get('pesapal/ipn', [GatewayWebhookController::class, 'webhook'])->name('billing.gateway.ipn');
});
