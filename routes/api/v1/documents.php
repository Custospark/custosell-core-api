<?php

use App\Http\Controllers\Api\DocumentCabinetController;
use App\Http\Controllers\Api\DocumentController;
use App\Http\Controllers\Api\DocumentFolderController;
use App\Http\Controllers\Api\DocumentTagController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:sanctum', 'business.active', 'subscription.active', 'module:documents'])->group(function () {
    Route::get('/documents/cabinets', [DocumentCabinetController::class, 'index']);
    Route::post('/documents/cabinets', [DocumentCabinetController::class, 'store']);
    Route::get('/documents/cabinets/{id}', [DocumentCabinetController::class, 'show'])->whereNumber('id');
    Route::patch('/documents/cabinets/{id}', [DocumentCabinetController::class, 'update'])->whereNumber('id');
    Route::delete('/documents/cabinets/{id}', [DocumentCabinetController::class, 'destroy'])->whereNumber('id');

    Route::get('/documents/activity', [DocumentCabinetController::class, 'activity']);
    Route::get('/documents/vault-appearance', [DocumentCabinetController::class, 'vaultAppearance']);
    Route::patch('/documents/vault-appearance', [DocumentCabinetController::class, 'updateVaultAppearance']);
    Route::get('/documents/accessible-members', [DocumentCabinetController::class, 'accessibleMembers']);

    Route::get('/documents/tags', [DocumentTagController::class, 'index']);
    Route::post('/documents/tags', [DocumentTagController::class, 'store']);

    Route::get('/documents/folders/tree', [DocumentFolderController::class, 'tree']);
    Route::get('/documents/folders/children', [DocumentFolderController::class, 'children']);
    Route::post('/documents/folders', [DocumentFolderController::class, 'store']);
    Route::get('/documents/folders/{id}', [DocumentFolderController::class, 'show'])->whereNumber('id');
    Route::get('/documents/folders/{id}/contents', [DocumentFolderController::class, 'contents'])->whereNumber('id');
    Route::get('/documents/folders/{id}/export', [DocumentFolderController::class, 'export'])->whereNumber('id');
    Route::post('/documents/folders/{id}/email', [DocumentFolderController::class, 'email'])->whereNumber('id');
    Route::patch('/documents/folders/{id}', [DocumentFolderController::class, 'update'])->whereNumber('id');
    Route::delete('/documents/folders/{id}', [DocumentFolderController::class, 'destroy'])->whereNumber('id');

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
