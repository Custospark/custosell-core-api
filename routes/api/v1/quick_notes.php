<?php

use App\Http\Controllers\Api\QuickNoteController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:sanctum', 'business.active', 'subscription.active'])->group(function () {
    Route::get('/quick-notes', [QuickNoteController::class, 'index']);
    Route::post('/quick-notes', [QuickNoteController::class, 'store']);
    Route::get('/quick-notes/{note_id}', [QuickNoteController::class, 'show'])->whereNumber('note_id');
    Route::put('/quick-notes/{note_id}', [QuickNoteController::class, 'update'])->whereNumber('note_id');
    Route::patch('/quick-notes/{note_id}', [QuickNoteController::class, 'update'])->whereNumber('note_id');
    Route::delete('/quick-notes/{note_id}', [QuickNoteController::class, 'destroy'])->whereNumber('note_id');
});
