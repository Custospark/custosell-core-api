<?php

use App\Http\Controllers\Api\CurrencyController;
use Illuminate\Support\Facades\Route;

Route::get('currencies/convert', [CurrencyController::class, 'convert']);
