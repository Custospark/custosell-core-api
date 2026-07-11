<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\UserController;
use Illuminate\Support\Facades\Route;

Route::post('/auth/register', [AuthController::class, 'register']);
Route::post('/auth/login', [AuthController::class, 'login']);
Route::post('/auth/forgot-password', [AuthController::class, 'forgotPassword']);
Route::post('/auth/reset-password', [AuthController::class, 'resetPassword']);

Route::middleware(['auth:sanctum', 'business.active'])->group(function () {
    Route::post('/auth/logout', [AuthController::class, 'logout']);
    Route::get('/auth/me', [AuthController::class, 'me']);
    Route::put('/auth/profile', [UserController::class, 'updateProfile']);
    Route::get('/auth/onboarding', [\App\Http\Controllers\Api\OnboardingController::class, 'show']);
    Route::patch('/auth/onboarding', [\App\Http\Controllers\Api\OnboardingController::class, 'update']);
});

Route::middleware(['auth:sanctum', 'business.active', 'module:settings'])->group(function () {
    Route::apiResource('users', UserController::class);
});
