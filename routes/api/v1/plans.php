<?php

use App\Http\Controllers\Api\PlanController;
use Illuminate\Support\Facades\Route;

Route::apiResource('plans', PlanController::class);
