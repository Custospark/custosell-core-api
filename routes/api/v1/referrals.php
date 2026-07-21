<?php

use App\Http\Controllers\Api\ReferralController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:sanctum', 'business.active'])->group(function () {
    Route::prefix('referrals')->group(function () {
        Route::get('/', [ReferralController::class, 'index']);
        Route::get('{id}', [ReferralController::class, 'show']);
        Route::get('business/{businessId}', [ReferralController::class, 'byBusiness']);
        Route::get('code/{codeId}', [ReferralController::class, 'byCode']);
    });
});
