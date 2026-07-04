<?php

use App\Http\Controllers\Api\AccountingPeriodController;
use App\Http\Controllers\Api\ChartOfAccountController;
use App\Http\Controllers\Api\FixedAssetController;
use App\Http\Controllers\Api\GeneralLedgerController;
use App\Http\Controllers\Api\JournalEntryController;
use App\Http\Controllers\Api\RatioController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:sanctum', 'business.active', 'module:accounting'])->group(function () {
    Route::get('/chart-of-accounts', [ChartOfAccountController::class, 'index']);
    Route::get('/chart-of-accounts/tree', [ChartOfAccountController::class, 'tree']);
    Route::get('/chart-of-accounts/{id}', [ChartOfAccountController::class, 'show'])->whereNumber('id');
    Route::post('/chart-of-accounts', [ChartOfAccountController::class, 'store']);
    Route::put('/chart-of-accounts/{id}', [ChartOfAccountController::class, 'update'])->whereNumber('id');
    Route::delete('/chart-of-accounts/{id}', [ChartOfAccountController::class, 'destroy'])->whereNumber('id');

    Route::get('/accounting-periods', [AccountingPeriodController::class, 'index']);
    Route::get('/accounting-periods/current', [AccountingPeriodController::class, 'current']);
    Route::post('/accounting-periods', [AccountingPeriodController::class, 'store']);
    Route::post('/accounting-periods/{id}/close', [AccountingPeriodController::class, 'close'])->whereNumber('id');
    Route::post('/accounting-periods/{id}/reopen', [AccountingPeriodController::class, 'reopen'])->whereNumber('id');

    Route::get('/journal-entries', [JournalEntryController::class, 'index']);
    Route::get('/journal-entries/{id}', [JournalEntryController::class, 'show'])->whereNumber('id');
    Route::get('/journal-entries/{id}/lines', [JournalEntryController::class, 'lines'])->whereNumber('id');
    Route::post('/journal-entries', [JournalEntryController::class, 'store']);
    Route::post('/journal-entries/{id}/post', [JournalEntryController::class, 'post'])->whereNumber('id');
    Route::post('/journal-entries/{id}/reverse', [JournalEntryController::class, 'reverse'])->whereNumber('id');
    Route::delete('/journal-entries/{id}', [JournalEntryController::class, 'destroy'])->whereNumber('id');

    Route::get('/general-ledger', [GeneralLedgerController::class, 'index']);
    Route::get('/general-ledger/trial-balance', [GeneralLedgerController::class, 'trialBalance']);
    Route::get('/general-ledger/profit-loss', [GeneralLedgerController::class, 'profitLoss']);
    Route::get('/general-ledger/balance-sheet', [GeneralLedgerController::class, 'balanceSheet']);
    Route::get('/general-ledger/cash-flow', [GeneralLedgerController::class, 'cashFlow']);
    Route::get('/general-ledger/equity', [GeneralLedgerController::class, 'equity']);

    Route::get('/fixed-assets', [FixedAssetController::class, 'index']);
    Route::get('/fixed-assets/{id}', [FixedAssetController::class, 'show'])->whereNumber('id');
    Route::post('/fixed-assets', [FixedAssetController::class, 'store']);
    Route::put('/fixed-assets/{id}', [FixedAssetController::class, 'update'])->whereNumber('id');
    Route::delete('/fixed-assets/{id}', [FixedAssetController::class, 'destroy'])->whereNumber('id');
    Route::post('/fixed-assets/run-depreciation', [FixedAssetController::class, 'runDepreciation']);
    Route::get('/fixed-assets/{id}/schedule', [FixedAssetController::class, 'schedule'])->whereNumber('id');

    Route::get('/ratios', [RatioController::class, 'index']);
    Route::get('/ratios/trends', [RatioController::class, 'trends']);

    Route::get('/accounting/export/{type}', [\App\Http\Controllers\Api\AccountingExportController::class, 'export']);
});
