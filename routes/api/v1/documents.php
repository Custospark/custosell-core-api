<?php

use App\Http\Controllers\Api\DocumentController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:sanctum', 'business.active', 'module:documents'])->group(function () {
    Route::get('/documents/cabinets', [DocumentController::class, 'indexCabinets']);
    Route::post('/documents/cabinets', [DocumentController::class, 'storeCabinet']);
    Route::get('/documents/cabinets/{id}', [DocumentController::class, 'showCabinet'])->whereNumber('id');
    Route::patch('/documents/cabinets/{id}', [DocumentController::class, 'updateCabinet'])->whereNumber('id');
    Route::delete('/documents/cabinets/{id}', [DocumentController::class, 'destroyCabinet'])->whereNumber('id');

    Route::get('/documents/activity', [DocumentController::class, 'activity']);
    Route::get('/documents/vault-appearance', [DocumentController::class, 'vaultAppearance']);
    Route::patch('/documents/vault-appearance', [DocumentController::class, 'updateVaultAppearance']);
    Route::get('/documents/accessible-members', [DocumentController::class, 'accessibleMembers']);
    Route::get('/documents/tags', [DocumentController::class, 'tags']);
    Route::post('/documents/tags', [DocumentController::class, 'storeTag']);

    Route::get('/documents/folders/tree', [DocumentController::class, 'folderTree']);
    Route::get('/documents/folders/children', [DocumentController::class, 'folderChildren']);
    Route::post('/documents/folders', [DocumentController::class, 'storeFolder']);
    Route::get('/documents/folders/{id}', [DocumentController::class, 'showFolder'])->whereNumber('id');
    Route::get('/documents/folders/{id}/contents', [DocumentController::class, 'folderContents'])->whereNumber('id');
    Route::get('/documents/folders/{id}/export', [DocumentController::class, 'exportFolder'])->whereNumber('id');
    Route::post('/documents/folders/{id}/email', [DocumentController::class, 'emailFolder'])->whereNumber('id');
    Route::patch('/documents/folders/{id}', [DocumentController::class, 'updateFolder'])->whereNumber('id');
    Route::delete('/documents/folders/{id}', [DocumentController::class, 'destroyFolder'])->whereNumber('id');

    Route::get('/documents', [DocumentController::class, 'index']);
    Route::post('/documents/upload', [DocumentController::class, 'upload']);
    Route::post('/documents/link', [DocumentController::class, 'storeLink']);
    Route::get('/documents/{id}', [DocumentController::class, 'show'])->whereNumber('id');
    Route::get('/documents/{id}/content', [DocumentController::class, 'showContent'])->whereNumber('id');
    Route::put('/documents/{id}/content', [DocumentController::class, 'updateContent'])->whereNumber('id');
    Route::patch('/documents/{id}', [DocumentController::class, 'update'])->whereNumber('id');
    Route::delete('/documents/{id}', [DocumentController::class, 'destroy'])->whereNumber('id');
    Route::post('/documents/{id}/view', [DocumentController::class, 'recordView'])->whereNumber('id');
    Route::post('/documents/{id}/download', [DocumentController::class, 'recordDownload'])->whereNumber('id');
    Route::post('/documents/{id}/email', [DocumentController::class, 'emailDocument'])->whereNumber('id');
});
