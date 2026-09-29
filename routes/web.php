<?php

use App\Http\Controllers\Api\QuotationController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::get('/quotations', [QuotationController::class, 'page']);
Route::post('/quotations/download', [QuotationController::class, 'download']);
