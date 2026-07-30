<?php

use App\Http\Controllers\Api\GuideFaqController;
use Illuminate\Support\Facades\Route;

Route::get('/public/faqs', [GuideFaqController::class, 'index']);
