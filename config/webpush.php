<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Web Push (VAPID)
    |--------------------------------------------------------------------------
    |
    | VAPID lets the browser trust pushes coming from this server. The public
    | key is exposed to the client for `pushManager.subscribe(...)`, the
    | private key stays server-side to sign push HTTP requests.
    |
    */
    'enabled' => (bool) env('WEB_PUSH_ENABLED', true),
    'subject' => env('VAPID_SUBJECT', 'mailto:info@custospark.com'),
    'public_key' => env('VAPID_PUBLIC_KEY'),
    'private_key' => env('VAPID_PRIVATE_KEY'),

    /*
    |--------------------------------------------------------------------------
    | Push notification defaults
    |--------------------------------------------------------------------------
    | `icon` is relative to the service-worker scope; `url` is the in-app
    | route the browser opens when the notification is tapped.
    |
    */
    'icon' => env('WEB_PUSH_ICON', '/icons/icon-192.png'),
    'route' => env('WEB_PUSH_ROUTE', '/account/notifications'),
];