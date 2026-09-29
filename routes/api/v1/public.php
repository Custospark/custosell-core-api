<?php

use App\Http\Controllers\Api\GuideFaqController;
use App\Http\Controllers\Api\QuotationController;
use Illuminate\Support\Facades\Route;

Route::get('/public/faqs', [GuideFaqController::class, 'index']);

Route::get('/quotations/packages', [QuotationController::class, 'packages']);
Route::get('/quotations/items', [QuotationController::class, 'items']);
Route::post('/quotations/estimate', [QuotationController::class, 'estimate']);
Route::post('/quotations/download', [QuotationController::class, 'download']);
