<?php

use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function () {
    Route::get('health', function () {
        return response()->json([
            'status' => 'ok',
            'version' => config('app.version'),
            'timestamp' => now()->toIso8601String(),
        ]);
    });
    require __DIR__ . '/api/v1/plans.php';
    require __DIR__ . '/api/v1/users.php';
    require __DIR__ . '/api/v1/businesses.php';
    require __DIR__ . '/api/v1/roles.php';
    require __DIR__ . '/api/v1/categories.php';
    require __DIR__ . '/api/v1/products.php';
    require __DIR__ . '/api/v1/customers.php';
    require __DIR__ . '/api/v1/shifts.php';
    require __DIR__ . '/api/v1/sales.php';
    require __DIR__ . '/api/v1/sale_items.php';
    require __DIR__ . '/api/v1/stock_movements.php';
    require __DIR__ . '/api/v1/subscriptions.php';
    require __DIR__ . '/api/v1/expense_categories.php';
    require __DIR__ . '/api/v1/expenses.php';
    require __DIR__ . '/api/v1/sync.php';
    require __DIR__ . '/api/v1/dashboard.php';
    require __DIR__ . '/api/v1/reports.php';
    require __DIR__ . '/api/v1/platform.php';
    require __DIR__ . '/api/v1/notifications.php';
    require __DIR__ . '/api/v1/guide.php';
    require __DIR__ . '/api/v1/accounting.php';
});
