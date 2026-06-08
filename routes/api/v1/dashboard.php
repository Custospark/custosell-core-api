<?php

use App\Http\Controllers\Api\DashboardController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:sanctum', 'business.active', 'module:dashboard'])->group(function () {
    Route::get('/dashboard/summary', [DashboardController::class, 'summary']);
});
