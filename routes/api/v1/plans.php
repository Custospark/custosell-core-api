<?php

use App\Http\Controllers\Api\PlanController;
use Illuminate\Support\Facades\Route;

Route::get('plans/active', [PlanController::class, 'active']);
Route::apiResource('plans', PlanController::class);
