<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\AccountSecurityController;
use App\Http\Controllers\Api\UserController;
use Illuminate\Support\Facades\Route;

Route::post('/auth/register', [AuthController::class, 'register']);
Route::post('/auth/login', [AuthController::class, 'login']);
Route::post('/auth/verify/send', [AuthController::class, 'sendVerificationCode'])->middleware('throttle:3,1');
Route::post('/auth/verify', [AuthController::class, 'verify'])->middleware('throttle:10,1');
Route::post('/auth/forgot-password', [AuthController::class, 'forgotPassword'])->middleware('throttle:3,1');
Route::post('/auth/reset-password', [AuthController::class, 'resetPassword']);

Route::middleware(['auth:sanctum', 'business.active'])->group(function () {
    Route::post('/auth/logout', [AuthController::class, 'logout']);
    Route::get('/auth/me', [AuthController::class, 'me']);
    Route::put('/auth/profile', [UserController::class, 'updateProfile']);
    Route::post('/auth/two-factor', [AccountSecurityController::class, 'toggleTwoFactor']);
    Route::post('/auth/password/initiate', [AuthController::class, 'initiatePasswordChange'])->middleware('throttle:3,1');
    Route::post('/auth/password/confirm', [AuthController::class, 'confirmPasswordChange'])->middleware('throttle:10,1');
    Route::post('/auth/profile/initiate', [AuthController::class, 'initiateProfileChange'])->middleware('throttle:3,1');
    Route::post('/auth/profile/confirm', [AuthController::class, 'confirmProfileChange'])->middleware('throttle:10,1');
    Route::get('/auth/activity', [AccountSecurityController::class, 'activity']);
    Route::get('/auth/onboarding', [\App\Http\Controllers\Api\OnboardingController::class, 'show']);
    Route::patch('/auth/onboarding', [\App\Http\Controllers\Api\OnboardingController::class, 'update']);
});

Route::middleware(['auth:sanctum', 'business.active', 'subscription.active', 'module:settings'])->group(function () {
    Route::get('users/lookup', [UserController::class, 'lookup']);
    Route::post('users/attach', [UserController::class, 'attach']);
    Route::post('users/{user}/detach', [UserController::class, 'detach']);
    Route::apiResource('users', UserController::class);
});
