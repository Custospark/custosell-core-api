<?php

use App\Http\Controllers\Api\ReferralCodeController;
use Illuminate\Support\Facades\Route;

// Public validation endpoint (no auth required — used on registration page)
Route::get('referral-codes/validate', [ReferralCodeController::class, 'validateCode']);

Route::middleware(['auth:sanctum', 'business.active'])->group(function () {
    Route::apiResource('referral-codes', ReferralCodeController::class);
});
