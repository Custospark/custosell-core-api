<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Cross-Origin Resource Sharing (CORS) Configuration
    |--------------------------------------------------------------------------
    |
    | Settings here control CORS for API and Sanctum SPA auth routes.
    | For production, set FRONTEND_URL in .env to the deployed frontend origin.
    |
    | Electron file:// origins send a "null" Origin header.
    | 'null' is included below to support that case.
    |
    */

    'paths' => ['api/*', 'sanctum/csrf-cookie'],

    'allowed_methods' => ['*'],

    'allowed_origins' => [
        'null',
    ],

    'allowed_origins_patterns' => [
        '/^https?:\/\/(localhost|127\.0\.0\.1)(:\d+)?$/',
    ],

    'allowed_headers' => ['*'],

    'exposed_headers' => [],

    'max_age' => 0,

    'supports_credentials' => true,

];
