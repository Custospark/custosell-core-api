<?php

use App\Http\Controllers\Api\EfrisStatusController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:sanctum', 'business.active'])->group(function () {
    Route::get('/efris/status', EfrisStatusController::class);
});
