<?php

use App\Http\Controllers\Api\RoleController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:sanctum', 'business.active', 'module:settings'])->group(function () {
    Route::apiResource('roles', RoleController::class);
});
