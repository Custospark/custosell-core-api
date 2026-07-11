<?php

declare(strict_types=1);

/**
 * URA EFRIS (Electronic Fiscal Receipting and Invoicing System).
 *
 * Master switch: EFRIS_ENABLED. When false, Custosell never calls URA —
 * POS and invoices behave as today (no fiscal queue).
 *
 * Setup procedures: Frontend docs/compliance/efris-setup.md
 * Product ADR: Frontend docs/adr/2026-07-12-efris-fiscalization.md
 */
return [

    /*
    |--------------------------------------------------------------------------
    | Master switch — use EFRIS or not
    |--------------------------------------------------------------------------
    |
    | false (default): fiscalization fully off for this deployment.
    | true: queue fiscal payloads for POS sales + sales invoices (see scope).
    |
    */
    'enabled' => filter_var(env('EFRIS_ENABLED', false), FILTER_VALIDATE_BOOLEAN),

    /*
    |--------------------------------------------------------------------------
    | Country / jurisdiction (configurable for future e-invoicing regimes)
    |--------------------------------------------------------------------------
    |
    | v1 implementation is Uganda (UG) EFRIS only. Other country codes may be
    | added later behind the same enabled flag + provider drivers.
    |
    */
    'country' => strtoupper((string) env('EFRIS_COUNTRY', 'UG')),

    /*
    |--------------------------------------------------------------------------
    | Integration mode
    |--------------------------------------------------------------------------
    |
    | api     — Direct URA EFRIS system-to-system API (chosen for v1).
    | device  — Hardware EFD / fiscal device (not used in v1).
    |
    */
    'mode' => env('EFRIS_MODE', 'api'),

    /*
    |--------------------------------------------------------------------------
    | What to fiscalize
    |--------------------------------------------------------------------------
    */
    'scope' => [
        'pos_sales' => filter_var(env('EFRIS_FISCALIZE_POS', true), FILTER_VALIDATE_BOOLEAN),
        'sales_invoices' => filter_var(env('EFRIS_FISCALIZE_INVOICES', true), FILTER_VALIDATE_BOOLEAN),
    ],

    /*
    |--------------------------------------------------------------------------
    | Offline behaviour
    |--------------------------------------------------------------------------
    |
    | sync_later — allow checkout offline; enqueue fiscal job until online (v1).
    | block      — refuse checkout until URA responds (rejected for v1).
    |
    */
    'offline' => env('EFRIS_OFFLINE_MODE', 'sync_later'),

    /*
    |--------------------------------------------------------------------------
    | Environment endpoints
    |--------------------------------------------------------------------------
    */
    'environment' => env('EFRIS_ENVIRONMENT', 'sandbox'), // sandbox | production

    'base_url' => env(
        'EFRIS_BASE_URL',
        env('EFRIS_ENVIRONMENT', 'sandbox') === 'production'
            ? 'https://efrisws.ura.go.ug'
            : 'https://efristest.ura.go.ug'
    ),

    /*
    |--------------------------------------------------------------------------
    | Pilot / sandbox credentials (per deployment — put real values in .env)
    |--------------------------------------------------------------------------
    |
    | Never commit real TIN, passwords, keys, or device numbers.
    | How to obtain: docs/compliance/efris-setup.md
    |
    */
    'tin' => env('EFRIS_TIN'),
    'device_no' => env('EFRIS_DEVICE_NO'),
    'branch_id' => env('EFRIS_BRANCH_ID'),
    'api_username' => env('EFRIS_API_USERNAME'),
    'api_password' => env('EFRIS_API_PASSWORD'),
    'private_key_path' => env('EFRIS_PRIVATE_KEY_PATH'),
    'public_key_path' => env('EFRIS_PUBLIC_KEY_PATH'),

];
