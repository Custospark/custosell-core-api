<?php

use App\Http\Controllers\Api\StaffTransferController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:sanctum', 'business.active', 'subscription.active', 'module:settings,hr'])
    ->prefix('staff-transfers')
    ->group(function () {
        Route::get('/', [StaffTransferController::class, 'index']);
        Route::get('/{id}', [StaffTransferController::class, 'show'])->whereNumber('id');
        Route::post('/', [StaffTransferController::class, 'store']);
    });
