<?php

use App\Http\Controllers\Api\QuickNoteController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:sanctum', 'business.active', 'subscription.active'])->group(function () {
    Route::get('/quick-notes', [QuickNoteController::class, 'index']);
    Route::post('/quick-notes', [QuickNoteController::class, 'store']);
    Route::get('/quick-notes/{quick_note}', [QuickNoteController::class, 'show'])->whereNumber('quick_note');
    Route::put('/quick-notes/{quick_note}', [QuickNoteController::class, 'update'])->whereNumber('quick_note');
    Route::patch('/quick-notes/{quick_note}', [QuickNoteController::class, 'update'])->whereNumber('quick_note');
    Route::delete('/quick-notes/{quick_note}', [QuickNoteController::class, 'destroy'])->whereNumber('quick_note');
});
