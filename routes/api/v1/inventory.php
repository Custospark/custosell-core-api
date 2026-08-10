<?php

use App\Http\Controllers\Api\InventoryOverviewController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:sanctum', 'business.active', 'subscription.active', 'module:inventory'])->group(function () {
    Route::get('/inventory/overview', [InventoryOverviewController::class, 'show']);
});