<?php

use App\Http\Controllers\Api\ReferralCodeController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:sanctum', 'business.active'])->group(function () {
    Route::apiResource('referral-codes', ReferralCodeController::class);
});
