<?php

use App\Http\Controllers\Api\BusinessSocialLinkController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:sanctum', 'business.active', 'business.owner', 'module:settings'])->group(function () {
    Route::apiResource('business-social-links', BusinessSocialLinkController::class);
});