<?php

use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function () {
    require __DIR__ . '/api/v1/plans.php';
    require __DIR__ . '/api/v1/users.php';
    require __DIR__ . '/api/v1/businesses.php';
    require __DIR__ . '/api/v1/roles.php';
});
